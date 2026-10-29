<?php
declare(strict_types=1);
namespace BotanikArt;
require_once __DIR__.'/CatalogStore.php';

final class TelegramFailure extends \RuntimeException {
    public function __construct(public int $apiCode,public int $retryAfter=60){parent::__construct('Telegram request failed ('.$apiCode.')');}
}
final class TelegramBot {
    public \PDO $db;
    private mixed $http=null;
    public function __construct(private array $config,private string $private,private mixed $transport=null){
        $dir=$private.'/telegram';if(!is_dir($dir))mkdir($dir,0700,true);
        $this->db=new \PDO('sqlite:'.$dir.'/bot.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);@chmod($dir.'/bot.sqlite',0600);
        $this->db->exec('PRAGMA busy_timeout=10000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS updates(id INTEGER PRIMARY KEY,chat TEXT,created INTEGER); CREATE TABLE IF NOT EXISTS chats(id TEXT PRIMARY KEY,username TEXT,created INTEGER); CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT); CREATE TABLE IF NOT EXISTS outbox(id TEXT PRIMARY KEY,method TEXT,payload TEXT,created INTEGER,sent INTEGER DEFAULT 0,next_try INTEGER DEFAULT 0,lease INTEGER DEFAULT 0,attempts INTEGER DEFAULT 0,error INTEGER DEFAULT 0)');
    }
    public static function installed(string $private): ?self {return is_file($private.'/telegram-config.php')?new self(require $private.'/telegram-config.php',$private):null;}
    public function request(string $method,array $payload=[]): mixed {
        if($this->transport)return ($this->transport)($method,$payload);
        if(!preg_match('/^[A-Za-z]+$/D',$method))throw new \InvalidArgumentException('Invalid Telegram method');
        $h=$this->http??=curl_init();
        curl_setopt($h,CURLOPT_URL,'https://api.telegram.org/bot'.$this->config['token'].'/'.$method);
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        // Retry only failures before a request could reach Telegram. Reuse the TLS connection for callbacks/cards.
        for($attempt=0;$attempt<3;$attempt++){
            $raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
            if($raw!==false||$status!==0||curl_getinfo($h,CURLINFO_PRETRANSFER_TIME)>0||!in_array(curl_errno($h),[6,7,28,35],true))break;
        }
        $data=is_string($raw)?json_decode($raw,true):null;
        if($status!==200||!($data['ok']??false))throw new TelegramFailure((int)($data['error_code']??$status),max(1,min(3600,(int)($data['parameters']['retry_after']??60))));
        return $data['result']??true;
    }
    private function setting(string $name): ?string {$q=$this->db->prepare('SELECT value FROM settings WHERE name=?');$q->execute([$name]);$v=$q->fetchColumn();return $v===false?null:$v;}
    public function ensureWebhook(): void {
        $fingerprint=hash('sha256',$this->config['webhook_secret'].$this->config['site_url']);
        if($this->setting('webhook_ready')===$fingerprint)return;
        $url=$this->config['site_url'].'/api/telegram-webhook.php';
        $this->request('setWebhook',['url'=>$url,'secret_token'=>$this->config['webhook_secret'],'allowed_updates'=>['message','callback_query'],'max_connections'=>4,'drop_pending_updates'=>false]);
        $info=$this->request('getWebhookInfo');if(($info['url']??'')!==$url)throw new \RuntimeException('Webhook not confirmed');
        $this->put('webhook_ready',$fingerprint);
    }
    public function workerHeartbeat(): void {$this->put('worker_last_run',(string)time());}
    public function owner(): ?string {return $this->setting('owner');}
    private function put(string $name,string $value): void {$q=$this->db->prepare('INSERT INTO settings(name,value) VALUES(?,?) ON CONFLICT(name) DO UPDATE SET value=excluded.value');$q->execute([$name,$value]);}
    public function pairingLink(): string {$code=bin2hex(random_bytes(20));$this->put('pair_hash',hash('sha256',$code));$this->put('pair_expires',(string)(time()+172800));return 'https://t.me/'.$this->config['username'].'?start=owner_'.$code;}
    private function queue(string $id,string $method,array $payload): void {$q=$this->db->prepare('INSERT OR IGNORE INTO outbox(id,method,payload,created) VALUES(?,?,?,?)');$q->execute([$id,$method,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()]);}
    private function say(string $key,string $chat,string $text,array $rows=[]): void {$p=['chat_id'=>$chat,'text'=>$text,'parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true]];if($rows)$p['reply_markup']=['inline_keyboard'=>$rows];$this->queue($key,'sendMessage',$p);}
    private function esc(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private function price($n): string {return number_format((float)$n,0,',',' ').' ₽';}
    private function button(string $label,string $action,bool $url=false,string $style=''): array {$b=['text'=>$label,$url?'url':'callback_data'=>$action];if($style)$b['style']=$style;return $b;}
    private function contact(): array {return $this->button('💬 Написать нам','https://t.me/Ekaterina_Freed',true,'primary');}
    private function catalog(): array {$s=new CatalogStore($this->private.'/catalogue',dirname($this->private,2).'/data/catalog.json');return $s->publicCatalog()['products'];}
    private function menu(): array {return [[$this->button('🌿 Флорариумы','c:florarium:0',false,'success')],[$this->button('🪴 Растения','c:plants:0',false,'success'),$this->button('🪵 Материалы','c:material:0',false,'primary')],[$this->button('🌐 Наш сайт',$this->config['site_url'],true),$this->contact()]];}
    private function imageUrl(string $image): string {return str_starts_with($image,'https://')?$image:$this->config['site_url'].'/'.ltrim($image,'/');}
    private function photo(string $key,string $chat,string $image,string $caption,array $rows=[]): void {
        if(!$image){$this->say($key,$chat,$caption,$rows);return;}
        $body=['chat_id'=>$chat,'photo'=>$this->imageUrl($image),'caption'=>$caption,'parse_mode'=>'HTML'];if($rows)$body['reply_markup']=['inline_keyboard'=>$rows];$this->queue($key,'sendPhoto',$body);
    }
    private function welcome(string $key,string $chat): void {
        $image='';$file=dirname($this->private,2).'/index.html';
        if(is_file($file)){$doc=new \DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.file_get_contents($file),LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);$nodes=(new \DOMXPath($doc))->query('//*[contains(concat(" ",normalize-space(@class)," ")," hero-photo ")]//img');if($nodes->length)$image=$nodes->item(0)->getAttribute('src');}
        $this->photo($key,$chat,$image,$this->intro(),$this->menu());
    }
    public static function excerpt(string $text,int $budget=650): string {
        $text=trim(strip_tags(str_replace(['\\r\\n','\\n',"\r"],["\n","\n",''],$text)));
        $text=preg_replace('/(?<=[.!?])\h+(?=[🦕🌿🎁🌱🎨])/u',"\n\n",$text);
        $parts=preg_split('/\n\s*\n/u',$text,-1,PREG_SPLIT_NO_EMPTY);$result='';
        foreach(array_slice($parts,0,2) as $part){$candidate=$result===''?trim($part):$result."\n\n".trim($part);if(mb_strlen($candidate)<=$budget){$result=$candidate;continue;}
            // Keep complete sentences; never manufacture a mid-word ellipsis.
            foreach(preg_split('/(?<=[.!?])\s+/u',trim($part),-1,PREG_SPLIT_NO_EMPTY) as $sentence){$candidate=$result===''?$sentence:$result.' '.$sentence;if(mb_strlen($candidate)>$budget)break;$result=$candidate;}break;
        }return $result;
    }
    private function intro(): string {
        $file=dirname($this->private,2).'/index.html';$text='Создаём флорариумы, выращиваем растения и проводим мастер-классы.';
        if(is_file($file)){$doc=new \DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8">'.file_get_contents($file),LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);$nodes=(new \DOMXPath($doc))->query('//p[contains(concat(" ",normalize-space(@class)," ")," hero-description ")]');if($nodes->length)$text=trim($nodes->item(0)->textContent);}
        return '<b>Здравствуйте! Это БотаникАрт.</b>' . "\n\n".$this->esc($text)."\n\nВыберите, что вам интересно:";
    }
    public function checkoutContext(string $chat,string $product): string {$s=rtrim(strtr(base64_encode(json_encode(['chat'=>$chat,'product'=>$product,'expires'=>time()+3600],JSON_THROW_ON_ERROR)),'+/','-_'),'=');return $s.'.'.hash_hmac('sha256',$s,$this->config['context_secret']);}
    public static function verifyContext(string $token,string $product,array $config): string {
        if(strlen($token)>1024||substr_count($token,'.')!==1)throw new \InvalidArgumentException('Invalid bot checkout');[$body,$signature]=explode('.',$token,2);
        if(!hash_equals(hash_hmac('sha256',$body,$config['context_secret']),$signature))throw new \InvalidArgumentException('Invalid bot checkout');
        $data=json_decode((string)base64_decode(strtr($body,'-_','+/'),true),true);$id=(string)($data['chat']??'');
        if(!preg_match('/^[1-9][0-9]{0,18}$/D',$id)||($data['product']??'')!==$product||($data['expires']??0)<time())throw new \InvalidArgumentException('Expired bot checkout');return $id;
    }
    private function browse(string $key,string $chat,string $group,int $page): void {
        $groups=['florarium'=>['florarium','composition'],'plants'=>['potted','plant'],'potted'=>['potted'],'plant'=>['plant'],'material'=>['material']];$names=['florarium'=>'🌿 Флорариумы','plants'=>'🪴 Растения','potted'=>'🪴 Комнатные растения','plant'=>'🌱 Растения для флорариума','material'=>'🪵 Материалы'];
        if(!isset($groups[$group])){$this->welcome($key,$chat);return;}
        $items=array_values(array_filter($this->catalog(),fn($p)=>in_array($p['catalogGroup']??$p['category']??'', $groups[$group],true)));usort($items,fn($a,$b)=>((int)!$a['available'])<=>((int)!$b['available'])?:strnatcasecmp($a['title'],$b['title']));
        $page=max(0,min($page,max(0,(int)ceil(count($items)/5)-1)));$rows=[];$text='<b>'.$names[$group].'</b>'."\n".($page+1).' / '.max(1,(int)ceil(count($items)/5));$media=[];
        if(in_array($group,['plants','potted','plant'],true))$rows[] = [$this->button('🪴 Комнатные','c:potted:0',false,'success'),$this->button('🌱 Для флорариума','c:plant:0',false,'success')];
        foreach(array_slice($items,$page*5,5) as $i=>$p){
            $number=$page*5+$i+1;$summary=self::excerpt($p['description']??'',160);
            $caption='<b>'.$number.'. '.$this->esc($p['title']).'</b>'."\n".$this->price($p['price']).($p['available']?'':' · Под заказ');
            if($summary)$caption.="\n".$this->esc($summary);
            $text.="\n\n".$caption;
            if(!empty($p['images'][0]))$media[]=['type'=>'photo','media'=>$this->imageUrl($p['images'][0]),'caption'=>$caption,'parse_mode'=>'HTML'];
            $rows[]=[$this->button($number.'. '.mb_strimwidth($p['title'],0,48,'').' · '.$this->price($p['price']),'p:'.substr(hash('sha256',$p['id']),0,20).':'.$group.':'.$page,false,'primary')];
        }
        if(count($media)>1)$this->queue($key.':00-album','sendMediaGroup',['chat_id'=>$chat,'media'=>$media]);
        elseif($media)$this->photo($key.':00-album',$chat,$media[0]['media'],$media[0]['caption']);
        if(!$items)$text.="\n\nПока нет доступных товаров.";
        $nav=[$this->button('☰ Меню','menu')];if(($page+1)*5<count($items))$nav[]=$this->button('Дальше →','c:'.$group.':'.($page+1),false,'success');$rows[]=$nav;
        $this->say($key.':99-list',$chat,$text,$rows);
    }
    private function product(string $key,string $chat,string $hash,string $group='plants',int $page=0): void {
        $found=null;foreach($this->catalog() as $p)if(substr(hash('sha256',$p['id']),0,20)===$hash){$found=$p;break;}
        if(!$found){$this->say($key,$chat,'Этот товар больше не отображается в каталоге. Выберите другой.',$this->menu());return;}$p=$found;
        $caption='<b>'.$this->esc($p['title']).'</b>'."\n".$this->price($p['price']).' · '.($p['available']?'В наличии':'Под заказ')."\n\n".$this->esc(self::excerpt($p['description']??''));
        $url=$this->config['site_url'].'/catalog.html?product='.rawurlencode($p['id']);$rows=[];
        if($p['available']&&($p['purchaseMode']??'direct')!=='inquiry')$rows[]=[$this->button('🛍 Купить',$url.'&checkout=1&tg='.rawurlencode($this->checkoutContext($chat,$p['id'])),true,'success')];
        else $rows[]=[$this->button('💬 Согласовать заказ','https://t.me/Ekaterina_Freed?text='.rawurlencode('Здравствуйте! Интересует '.$p['title'].'. Хочу согласовать заказ.'),true,'success')];
        $rows[]=[$this->button('Подробнее',$url,true,'primary')];$rows[]=[$this->button('← Назад','c:'.$group.':'.$page),$this->button('☰ Меню','menu')];$rows[]=[$this->contact()];
        $image=$p['images'][0]??'';if($image!==''){$image=str_starts_with($image,'https://')?$image:$this->config['site_url'].'/'.ltrim($image,'/');$this->queue($key,'sendPhoto',['chat_id'=>$chat,'photo'=>$image,'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>['inline_keyboard'=>$rows]]);}else $this->say($key,$chat,$caption,$rows);
    }
    public function accept(array $u): void {
        if(!is_int($u['update_id']??null)||$u['update_id']<0)throw new \InvalidArgumentException('Invalid update');
        $message=$u['message']??$u['callback_query']['message']??[];$actor=$u['message']['from']??$u['callback_query']['from']??[];$chat=(string)($message['chat']['id']??'');
        if(($message['chat']['type']??'')!=='private'||!preg_match('/^[1-9][0-9]{0,18}$/D',$chat)||(string)($actor['id']??'')!==$chat||!empty($actor['is_bot']))return;
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $q=$this->db->prepare('INSERT OR IGNORE INTO updates(id,chat,created) VALUES(?,?,?)');$q->execute([$u['update_id'],$chat,time()]);if(!$q->rowCount()){$this->db->exec('COMMIT');return;}
            $q=$this->db->prepare('SELECT COUNT(*) FROM updates WHERE chat=? AND created>?');$q->execute([$chat,time()-60]);if($q->fetchColumn()>30){$this->db->exec('COMMIT');return;}
            $username=preg_match('/^[A-Za-z0-9_]{5,32}$/D',$actor['username']??'')?$actor['username']:'';$q=$this->db->prepare('INSERT INTO chats(id,username,created) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET username=excluded.username');$q->execute([$chat,$username,time()]);
            $key='update:'.$u['update_id'];$action=$u['callback_query']['data']??($u['message']['text']??'');
            if(isset($u['callback_query']['id']))$this->queue($key.':ack','answerCallbackQuery',['callback_query_id'=>$u['callback_query']['id']]);
            if(preg_match('/^\/start owner_([a-f0-9]{40})$/D',$action,$m)){
                $expected=strtolower(ltrim($this->config['owner_username']??'Ekaterina_Freed','@'));
                if($username!==''&&strtolower($username)===$expected&&time()<(int)$this->setting('pair_expires')&&hash_equals($this->setting('pair_hash')??'',hash('sha256',$m[1]))){$this->put('owner',$chat);$this->put('pair_hash','');$this->say($key,$chat,'Готово! Оплаченные заказы БотаникАрт будут приходить сюда. Команда /orders открывает последние заказы.',$this->menu());}
                else $this->say($key,$chat,'Ссылка подключения недействительна или предназначена для другого аккаунта.',$this->menu());
            }elseif($action==='/orders'){
                if($this->owner()!==$chat)$this->say($key,$chat,'Для покупок выберите раздел каталога.',$this->menu());
                else{require_once __DIR__.'/PaymentService.php';$orders=(new MerchantOrders(require $this->private.'/config.php'))->all();$text='<b>Последние оплаченные заказы</b>';foreach(array_slice($orders,0,5) as $o)$text.="\n\n".$this->esc($o['title']).' · '.$this->price($o['amount'])."\n".$this->esc($o['orderId']).' · '.($o['fulfillment']==='completed'?'Выполнен':'Новый');$this->say($key,$chat,$text,[[$this->button('Открыть заказы',$this->config['site_url'].'/catalogue/#orders',true)]]);}
            }elseif(preg_match('/^done:(ba-[a-f0-9]{24})$/D',$action,$m)){
                if($this->owner()===$chat){require_once __DIR__.'/PaymentService.php';(new MerchantOrders(require $this->private.'/config.php'))->complete($m[1],true);$this->say($key,$chat,'Заказ отмечен выполненным.');}else $this->say($key,$chat,'Эта кнопка доступна только студии.');
            }elseif(preg_match('/^c:([a-z]+):(\d{1,4})$/D',$action,$m))$this->browse($key,$chat,$m[1],(int)$m[2]);
            elseif(preg_match('/^p:([a-f0-9]{20})(?::(florarium|plants|potted|plant|material):(\d{1,4}))?$/D',$action,$m))$this->product($key,$chat,$m[1],$m[2]??'plants',(int)($m[3]??0));
            else $this->welcome($key,$chat);
            $this->db->exec('COMMIT');
        }catch(\Throwable $e){try{$this->db->exec('ROLLBACK');}catch(\Throwable){}throw $e;}
    }
    public function enqueuePaid(array $o): bool {
        if(!empty($o['testMode'])||empty($o['paid'])||($o['status']??'')!=='CONFIRMED')return false;
        $owner=$this->owner();$buyer=(string)($o['telegramChatId']??'');$id=$o['orderId'];
        if($owner){$text='<b>Оплачен новый заказ</b>'."\n\n".$this->esc($o['title'])."\n".$this->price($o['amount']/100)."\nЗаказ: ".$this->esc($id)."\nТелефон: ".$this->esc($o['phone']?:'не указан')."\nПочта: ".$this->esc($o['email']?:'не указана');$rows=[[$this->button('Заказ выполнен','done:'.$id)],[$this->button('Открыть в каталоге',$this->config['site_url'].'/catalogue/#orders',true)]];
            if($buyer){$q=$this->db->prepare('SELECT username FROM chats WHERE id=?');$q->execute([$buyer]);$username=$q->fetchColumn();if($username)$rows[]=[$this->button('Написать покупателю','https://t.me/'.$username,true)];}
            $this->say('paid:'.$id.':owner',$owner,$text,$rows);
        }
        if($buyer)$this->say('paid:'.$id.':buyer',$buyer,'<b>Спасибо за покупку!</b>'."\n\n".$this->esc($o['title'])."\nОплачено: ".$this->price($o['amount']/100)."\nЗаказ: ".$this->esc($id)."\n\nСвяжитесь со студией, чтобы согласовать самовывоз или доставку.",[[$this->contact()]]);
        return $owner!==null;
    }
    public function drain(int $limit=5): int {
        $done=0;for($n=0;$n<$limit;$n++){
            $this->db->exec('BEGIN IMMEDIATE');$q=$this->db->prepare("SELECT * FROM outbox WHERE sent=0 AND next_try<=? AND lease<? ORDER BY CASE WHEN method='answerCallbackQuery' THEN 0 ELSE 1 END,created,id LIMIT 1");$q->execute([time(),time()-120]);$job=$q->fetch(\PDO::FETCH_ASSOC);
            if(!$job){$this->db->exec('COMMIT');break;}$q=$this->db->prepare('UPDATE outbox SET lease=? WHERE id=?');$q->execute([time(),$job['id']]);$this->db->exec('COMMIT');$payload=json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);
            try{$this->request($job['method'],$payload);$q=$this->db->prepare('UPDATE outbox SET sent=?,lease=0 WHERE id=?');$q->execute([time(),$job['id']]);$done++;}
            catch(TelegramFailure $e){
                if($job['method']==='sendPhoto'&&$e->apiCode===400){$payload['text']=$payload['caption'];unset($payload['photo'],$payload['caption']);$q=$this->db->prepare("UPDATE outbox SET method='sendMessage',payload=?,lease=0,next_try=0 WHERE id=?");$q->execute([json_encode($payload,JSON_UNESCAPED_UNICODE),$job['id']]);}
                elseif($job['method']==='sendMediaGroup'&&$e->apiCode===400){$q=$this->db->prepare('UPDATE outbox SET sent=-1,lease=0,error=400 WHERE id=?');$q->execute([$job['id']]);}
                else{$terminal=$e->apiCode===403||($job['method']==='answerCallbackQuery'&&$e->apiCode===400);$q=$this->db->prepare('UPDATE outbox SET sent=?,lease=0,next_try=?,attempts=attempts+1,error=? WHERE id=?');$q->execute([$terminal?-1:0,time()+$e->retryAfter,$e->apiCode,$job['id']]);}
            }catch(\Throwable){$q=$this->db->prepare('UPDATE outbox SET lease=0,next_try=?,attempts=attempts+1 WHERE id=?');$q->execute([time()+60,$job['id']]);}
        }return $done;
    }
}
