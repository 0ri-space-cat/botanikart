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

const groupJs=fs.readFileSync(path.join(root,'catalogue/assortments.js'),'utf8');
new Function(groupJs);
for(const key of ['site','avito','flowwow']){
 assert(editor.includes('data-description-tab="'+key+'"'),'Missing description tab: '+key);
 assert(editor.includes('data-description-panel="'+key+'"'),'Missing description panel: '+key);
}
for(const key of ['avito','flowwow']){
 assert(editor.includes('data-description-inherit="'+key+'"'),'Missing website-description inheritance switch: '+key);
 const count=[...editor.matchAll(new RegExp('name="description_'+key+'"','g'))].length;
 assert.equal(count,1,'Duplicated platform description editor: '+key);
}
assert.equal([...editor.matchAll(/name="description"/g)].length,1,'Site description should be editable only once');
assert(js.includes('function updateDescriptionTabs()')&&js.includes('function selectDescriptionTab('));
assert(js.includes("description:descriptionInherited(key)?'':form.elements['description_'+key].value"),'Inherited descriptions must store an empty marketplace override');
assert(js.includes("descriptionDrafts[key]=input.value"),'Switching back from inherited description must restore the independent draft');
assert(groupJs.includes('updateDescriptionTabs();'),'Assortment mode must update which description tabs are available');
assert(groupJs.includes('id="ag-create-member"'),'Assortments must allow creating a separate website product');
assert(groupJs.includes("await openEditor(undefined,id)"),'New assortment item must be linked to selected group');
assert(css.includes('.description-tabs')&&css.includes('.description-inherited-preview'),'Tab presentation styles missing');
console.log('Editor UI checks passed: structure, required fields, photo actions, publication controls and mobile styles.');
// Test imported-vs-explicit ownership using the real status classifier.
const vm=require('node:vm');
const ownershipCode=js.slice(js.indexOf('function publicationOwned('),js.indexOf('const publicationPending='));
const owned=vm.runInNewContext(ownershipCode+';publicationOwned',{channelData:p=>p.channels});
assert.equal(owned({source:'flowwow',channels:{flowwow:{enabled:true,management:'site',state:'error'}}},'flowwow'),false,
  'Imported Flowwow product was treated as a new publication');
assert.equal(owned({source:'flowwow',channels:{flowwow:{enabled:true,management:'site',publicationRequested:true,state:'error'}}},'flowwow'),true,
  'An explicit Flowwow publication was hidden');
assert.equal(owned({source:'manual',channels:{flowwow:{enabled:true,management:'site',state:'error'}}},'flowwow'),true,
  'Existing locally authored products lost their publication status');
assert.equal(owned({source:'avito',channels:{avito:{enabled:true,management:'external',state:'error'}}},'avito'),false,
  'Imported Avito error became a local publication alert');

// Exercise the actual tracker: a shared Avito listing must render once.
const trackerSource=js.slice(js.indexOf('const publicationReceipts='),js.indexOf('\nlet connections='));
const rendered=[],storage={};
const localStorage={getItem:k=>storage[k]??null,setItem:(k,v)=>{storage[k]=v}};
const host={dataset:{},hidden:true,innerHTML:'',querySelectorAll(){
 return [...this.innerHTML.matchAll(/data-tracker-product="([^"]+)"/g)].map(m=>({dataset:{trackerProduct:m[1]}}));
}};
const variants=Array.from({length:8},(_,i)=>({id:'variant-'+i,source:'manual',version:1,title:'Plant '+i,
 channels:{avito:{enabled:true,management:'site',state:'queued',externalId:'shared-id'},flowwow:{enabled:false}}}));
const ctx={clearTimeout,setTimeout,localStorage,products:variants,$:()=>host,channelData:p=>p.channels,
 publicationPending:c=>!!c.enabled&&['queued','awaiting_report'].includes(c.state),
 publicationOwned:owned,escape:s=>s,publicationResults:p=>rendered.push(p.id)};
vm.runInNewContext(trackerSource+";rememberPublicationSave('variant-0');renderPublicationTracker()",ctx);
assert.equal(rendered.length,1,'One shared Avito listing appeared as multiple publication operations');
for(const p of variants){p.channels.avito.state='external';p.channels.avito.management='external';}
rendered.length=0;vm.runInNewContext("delete publicationWatching['variant-0'];renderPublicationTracker()",ctx);
assert.equal(host.hidden,true,'Imported external listings appeared in the progress panel');

// Historic successes/errors must never appear just because checkedAt was refreshed.
const p=variants[0];p.channels.avito.management='site';p.channels.avito.state='published';
p.channels.avito.checkedAt=new Date().toISOString();
vm.runInNewContext('renderPublicationTracker()',ctx);
assert.equal(host.hidden,true,'Old publication appeared without a local save');
vm.runInNewContext("rememberPublicationSave('variant-0');renderPublicationTracker()",ctx);
assert.equal(host.hidden,false,'A locally saved listing has no completion receipt');
vm.runInNewContext("publicationReceipts[products[0].id]=publicationReceiptKey(products[0]);delete publicationWatching[products[0].id];renderPublicationTracker()",ctx);
assert.equal(host.hidden,true,'Acknowledged completion reappeared');
p.channels.avito.checkedAt=new Date(Date.now()+1000).toISOString();
p.version=2;vm.runInNewContext('renderPublicationTracker()',ctx);
assert.equal(host.hidden,true,'A background refresh revived an old publication receipt');

// Pending work remains visible, but closing the notice is remembered across reloads.
p.channels.avito.state='queued';
vm.runInNewContext("rememberPublicationSave('variant-0');renderPublicationTracker()",ctx);
assert.equal(host.hidden,false,'A new explicit pending publication disappeared');
vm.runInNewContext("closePublicationReceipt('variant-0');renderPublicationTracker()",ctx);
assert.equal(host.hidden,true,'Closed publication alert immediately returned');
assert(storage['ba-publications-closed']?.includes('variant-0'),'Dismissal is not persisted');
p.channels.avito.state='published';
vm.runInNewContext('renderPublicationTracker()',ctx);
assert.equal(host.hidden,true,'Closed publication unexpectedly reappeared when status updated');

// Moderation is an acknowledged marketplace receipt, not an endless
// publication toast. The job must still be polled until it is active.
const finishedSource=js.slice(js.indexOf('const publicationFinished='),js.indexOf('function publicationWasClosed'));
const settled=vm.runInNewContext(finishedSource+';publicationFinished',{
 channelData:p=>p.channels,publicationOwned:owned,
 publicationPending:c=>['queued','awaiting_confirmation','moderation'].includes(c.state)
});
const flowwowProduct={id:'f1',source:'manual',channels:{
 avito:{enabled:false,management:'site',state:'off'},
 flowwow:{enabled:true,management:'site',state:'moderation'}
}};
assert.equal(settled(flowwowProduct),true,'Flowwow moderation must not leave a permanent notification');
assert(js.includes("if(!stillModerating)delete publicationWatching[p.id]"),
 'Moderation should dismiss its receipt without losing the later green publication receipt');
assert(js.includes('publicationReceipts[p.id]!==publicationReceiptKey(p)'),
 'A repeated moderation check must not recreate the dismissed receipt');
assert(js.includes('class="pending-mark accepted"'),
 'Moderation must remain visible as a small blue check in the product card');

flowwowProduct.channels.flowwow.state='awaiting_confirmation';
assert.equal(settled(flowwowProduct),false,'Missing acceptance must continue showing progress');
flowwowProduct.channels.flowwow.state='error';
flowwowProduct.channels.flowwow.error='API rejected product';
assert.equal(settled(flowwowProduct),false,'Real rejection must not disappear automatically');
console.log('Publication UI checks passed: imported ownership, deduplicated listings, genuine progress and persistent dismissal.');
