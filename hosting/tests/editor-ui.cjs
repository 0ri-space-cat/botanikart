'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.join(__dirname,'../..');
const html=fs.readFileSync(path.join(root,'catalogue/index.php'),'utf8');
const js=fs.readFileSync(path.join(root,'catalogue/app.js'),'utf8');
const css=fs.readFileSync(path.join(root,'catalogue/app.css'),'utf8');
new Function(js); // The editor and publication handlers must remain syntactically valid.
const editor=html.slice(html.indexOf('<dialog id="editor">'),html.indexOf('<dialog id="connections-dialog">'));
assert(editor.length>0);
const sections=['product-section-heading','photo-section-heading','publication-heading'].map(id=>editor.indexOf('id="'+id+'"'));
assert(sections.every(n=>n>=0)&&sections[0]<sections[1]&&sections[1]<sections[2],'Editor sections must be in product/photo/publishing order');
for(const name of ['title','price','status','catalogGroup','purchase_mode','description','visible','publish_avito','publish_flowwow','price_avito','price_flowwow','url']){
 assert(editor.includes('name="'+name+'"'),'Missing form field: '+name);
}
for(const id of ['photos','photo-input','photo-count','archive-product','restore-product','save','editor-save-summary','state-avito','state-flowwow']){
 assert(editor.includes('id="'+id+'"'),'Missing editor control: '+id);
}
assert(editor.includes('class="editor-advanced"'),'Optional URL field must remain available');
assert(js.includes('updateCategoryHint()')&&js.includes('updateEditorSaveSummary()'));
assert(js.includes('data-cover=')&&js.includes('data-remove=')&&js.includes('aria-pressed='));
assert(js.includes("images.splice(Number(remove.dataset.remove),1)"),'Photo deletion must remain wired');
assert(js.includes("images.unshift(...images.splice(Number(cover.dataset.cover),1))"),'Photo cover selection must remain wired');
assert(js.includes('data-channel-quick-status=')&&js.includes('updateChannelQuickStatus'));
assert(css.includes('.editor-section-heading')&&css.includes('.photo-empty')&&css.includes('.editor-save-context'));
assert(css.includes('env(safe-area-inset-bottom)'),'Sticky save area must account for iPhone safe area');
console.log('Editor UI checks passed: structure, required fields, photo actions, publication controls and mobile styles.');
// Exercise actual tracker code: an imported shared listing must be one operation.
const vm=require('node:vm');
const trackerSource=js.slice(js.indexOf('const publicationReceipts='),js.indexOf('\nlet connections='));
const rendered=[];const host={dataset:{},hidden:true,innerHTML:'',querySelectorAll(){return [...this.innerHTML.matchAll(/data-tracker-product="([^"]+)"/g)].map(m=>({dataset:{trackerProduct:m[1]}}));}};
const variants=Array.from({length:8},(_,i)=>({id:'variant-'+i,title:'Plant '+i,channels:{avito:{enabled:true,management:'site',state:'queued',externalId:'shared-id'},flowwow:{enabled:false}}}));
const ctx={clearTimeout,setTimeout,localStorage:{getItem:()=>null,setItem(){}},products:variants,$:()=>host,channelData:p=>p.channels,publicationPending:c=>!!c.enabled&&c.state==='queued',escape:s=>s,publicationResults:p=>rendered.push(p.id)};
vm.runInNewContext(trackerSource+';renderPublicationTracker()',ctx);
assert.equal(rendered.length,1,'Eight variants of one Avito listing appeared as eight uploads');
for(const p of variants){p.channels.avito.state='external';p.channels.avito.management='external';}
rendered.length=0;vm.runInNewContext('renderPublicationTracker()',ctx);
assert.equal(host.hidden,true,'Imported unchanged listings appeared in publication progress');
const stageSource=js.slice(js.indexOf('const publicationStage='),js.indexOf('\nfunction publicationResults'));
assert.equal(vm.runInNewContext(stageSource+";publicationStage({state:'external'})"),'published','Existing listing displayed as sending');
console.log('Publication UI checks passed: one operation per listing, no phantom imported uploads.');

// Completed notices disappear per revision, while pending and failed work stays visible.
variants[0].channels.avito.state='published';variants[0].channels.avito.checkedAt=new Date().toISOString();
vm.runInNewContext('renderPublicationTracker()',ctx);assert.equal(host.hidden,false);
vm.runInNewContext('publicationReceipts[products[0].id]=publicationReceiptKey(products[0]);renderPublicationTracker()',ctx);assert.equal(host.hidden,true,'Acknowledged completion reappeared');
variants[0].channels.avito.checkedAt=new Date(Date.now()+1000).toISOString();vm.runInNewContext('renderPublicationTracker()',ctx);assert.equal(host.hidden,true,'Report refresh revived acknowledged completion');
variants[0].version=2;vm.runInNewContext('renderPublicationTracker()',ctx);assert.equal(host.hidden,false,'New revision was hidden');
variants[0].channels.avito.state='queued';vm.runInNewContext('renderPublicationTracker()',ctx);assert.equal(host.hidden,false,'Pending publication was hidden');
console.log('Completion receipt checks passed.');

vm.runInNewContext('publicationClosedPending[products[0].id]=products[0].version;renderPublicationTracker()',ctx);assert.equal(host.hidden,true,'Manually dismissed pending notice returned');
variants[0].channels.avito.state='published';vm.runInNewContext('renderPublicationTracker()',ctx);assert.equal(host.hidden,false,'Completion did not reappear after closing pending notice');
