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
