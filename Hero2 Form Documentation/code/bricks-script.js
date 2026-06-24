/* ===========================================================================
   AMPY OFFERTFORMULÄR — DEL 3 av 3: JavaScript
   Klistra in i ETT globalt Bricks Code-element (sitewide).
   DEV: sätt ENDPOINT, SOURCE_FORM, POLICY_VERSION. PREVIEW härleds ur host (ampy.se=false).
   =========================================================================== */
(function(){
  /* ===== DEV-INSTÄLLNINGAR ===== */
  var ENDPOINT='/wp-json/ampy/v1/lead';      /* TODO dev */
  var SOURCE_FORM=3;                          /* TODO dev: bekräfta kod */
  /* PREVIEW = granskningsläge: visar payloaden istället för POST, och honorerar ?path. Härleds ur host så att
     produktionsdomänen ampy.se ALDRIG kan hamna i preview (en bortglömd boolean får ej tappa leads på 165 sidor).
     Endast file://, localhost/127.0.0.1 och *.github.io = preview. Staging på egen host kör skarp POST (önskat).
     I produktion kan denna även hårdkodas till false. */
  var PREVIEW=(location.protocol==='file:')||/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)||/\.github\.io$/.test(location.hostname);
  var POLICY_VERSION='ampy-privacy-2026-06';  /* TODO dev: bekräfta version */

  /* ===== HELPERS ===== */
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function toE164(s){s=String(s||'').replace(/[\s\-()]/g,'');if(/^00/.test(s))s='+'+s.slice(2);else if(/^0/.test(s))s='+46'+s.slice(1);else if(/^46/.test(s))s='+'+s;else if(/^\d/.test(s))s='+46'+s;return s;}
  function cap(s){return s.charAt(0).toUpperCase()+s.slice(1);}

  /* ===== TAXONOMI + MAPS (en diffbar källa) ===== */
  var PRIVAT_OPTS=['Elinstallation','Belysning','Kök och badrum','Laddbox','Energi & effekt','Elfel','Annat'];
  var ORG_OPTS=['Större elinstallation','Belysning','Laddbox','Service & underhåll','Elbesiktning','Energi & effekt','Elfel','Annat'];
  var SERVICE={vitvaror:'Vitvaror',utomhusbelysning:'Utomhusbelysning',strombrytare:'Strömbrytare','ugn-spis':'Ugn & spis',spotlights:'Spotlights','smarta-hem':'Smarta hem',luftvarmepump:'Luftvärmepump',lastbalansering:'Lastbalansering',kok:'El i kök',koksrenovering:'Köksrenovering',inomhusbelysning:'Inomhusbelysning',jordfelsbrytare:'Jordfelsbrytare',glodlampa:'Glödlampor',golvvarme:'Golvvärme','felsokning-av-el':'Felsökning av el',elrenovering:'Elrenovering',elcentral:'Elcentral',elbesiktning:'Elbesiktning',belysning:'Belysning',badrumsrenovering:'Badrumsrenovering',armatur:'Armatur',badrum:'El i badrum'};
  var EFX={
    villor:{kundtyp:'privat',vertical:'service',opts:PRIVAT_OPTS},
    radhus:{kundtyp:'privat',vertical:'service',opts:PRIVAT_OPTS},
    restauranger:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Storkök / fläkt / trefas','Större elinstallation','Belysning','Service & underhåll','Elfel','Laddbox','Annat']},
    hotell:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Större elinstallation','Belysning','Laddbox','Service & underhåll','Energi & effekt','Elbesiktning','Elfel','Annat']},
    kontor:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Större elinstallation','Belysning','Laddbox','Service & underhåll','Elfel','Annat']},
    butik:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Belysning','Större elinstallation','Service & underhåll','Laddbox','Elfel','Annat']},
    kommuner:{kundtyp:'foretag',vertical:'foretag_brf',orgLabel:'Förvaltning eller enhet',opts:['Större elinstallation','Belysning','Elbesiktning','Laddbox','Service & underhåll','Energi & effekt','Elfel','Annat']},
    idrottshallar:{kundtyp:'foretag',vertical:'foretag_brf',orgLabel:'Verksamhetens namn',opts:['Större elinstallation','Belysning','Energi & effekt','Service & underhåll','Elbesiktning','Elfel','Annat']},
    foretag:{kundtyp:'foretag',vertical:'foretag_brf',opts:ORG_OPTS},
    byggforetag:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Större elinstallation','Elbesiktning','Belysning','Service & underhåll','Elfel','Annat']},
    entreprenad:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Större elinstallation','Elbesiktning','Belysning','Laddbox','Service & underhåll','Annat']},
    bostadsrattsforening:{kundtyp:'brf',vertical:'foretag_brf',orgLabel:'Föreningens namn',opts:['Laddbox','Större elinstallation','Belysning','Elbesiktning','Service & underhåll','Energi & effekt','Elfel','Annat']},
    tredjepartsinstallationer:{kundtyp:'foretag',vertical:'foretag_brf',opts:['Större elinstallation','Laddbox','Belysning','Service & underhåll','Elbesiktning','Elfel','Annat']}
  };
  /* "Elektriker för <X>" (ägar-vald form; copy-finlir av singular/plural tas i copy-passet) */
  var FORLABEL={villor:'villa',radhus:'radhus',restauranger:'restaurang',hotell:'hotell',kontor:'kontor',butik:'butik',kommuner:'kommun',idrottshallar:'idrottshall',foretag:'företag',byggforetag:'byggföretag',entreprenad:'entreprenad',bostadsrattsforening:'bostadsrättsförening',tredjepartsinstallationer:'tredjepartsinstallation'};
  var SV_ORT={nacka:'Nacka',sodertalje:'Södertälje',sollentuna:'Sollentuna',taby:'Täby',jarfalla:'Järfälla',vaxholm:'Vaxholm',varmdo:'Värmdö',vallingby:'Vällingby',huddinge:'Huddinge',solna:'Solna',stockholm:'Stockholm','upplands-vasby':'Upplands Väsby',tyreso:'Tyresö',lidingo:'Lidingö',osteraker:'Österåker',bromma:'Bromma',sundbyberg:'Sundbyberg'};
  var BRADSKA={'Inom 24 timmar':'24h','Inom 72 timmar':'72h','Om 1-2 veckor':'1_2v','Flexibelt':'flexibel'};
  var UTIL=['/offert','/kopvillkor','/integritetspolicy','/cookiepolicy','/thank-you','/nyheter','/tillganglighet','/om-oss'];
  function ortName(s){return SV_ORT[s]||s.split('-').map(cap).join(' ');}

  /* ===== RESOLVER (case-insensitive, slash-säker, XSS-säker via teckenvitlista) ===== */
  function resolve(p){
    p=String(p||'').replace(/^https?:\/\/[^/]+/,'').replace(/[?#].*$/,'').toLowerCase().replace(/\/{2,}/g,'/').replace(/^\/+|\/+$/g,'');
    p='/'+p;
    if(/^\/eljour(\/|$)/.test(p)) return {render:false,kind:'eljour'};
    if(/^\/solcellsbatterier(\/|$)/.test(p)||/^\/laddboxar(\/|$)/.test(p)||/^\/batterilagring(\/|$)/.test(p)) return {render:false,kind:'produkt'};
    if(UTIL.some(function(u){return p===u||p.indexOf(u+'/')===0;})) return {render:false,kind:'utility'};
    var m;
    if((m=p.match(/^\/elservice\/([a-z0-9åäö-]+)$/))&&SERVICE[m[1]]) return {render:true,kundtyp:'privat',tjanst:SERVICE[m[1]],tjanstLocked:true,vertical:'service',source:p};
    if(p==='/elservice') return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
    var es=p.slice(1);
    if(Object.prototype.hasOwnProperty.call(EFX,es)){var efx=EFX[es];return {render:true,kundtyp:efx.kundtyp,ask:true,opts:efx.opts,forLabel:FORLABEL[es],orgLabel:efx.orgLabel,vertical:efx.vertical,source:p};}
    if(m=p.match(/^\/laddbox\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Laddbox',vertical:'laddbox',source:p};
    if(m=p.match(/^\/elinstallation\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,ort:ortName(m[1]),ortWord:'Elinstallation',vertical:'service',source:p};
    if(m=p.match(/^\/elektriker\/([a-z0-9åäö-]+)$/)) return {render:true,kundtyp:'privat',ask:true,ort:ortName(m[1]),ortWord:'Elektriker',vertical:'service',source:p};
    if(p==='/elektriker') return {render:true,kundtyp:'privat',ask:true,vertical:'service',source:p};
    if(p==='/laddbox') return {render:true,kundtyp:'privat',tjanst:'Laddbox',tjanstLocked:true,vertical:'laddbox',source:p};
    if(p==='/elinstallation') return {render:true,kundtyp:'privat',tjanst:'Elinstallation',tjanstLocked:true,vertical:'service',source:p};
    return {render:true,kundtyp:'privat',ask:true,vertical:'oklart',source:p};
  }

  var SEG={privat:'Privat',brf:'BRF',foretag:'Företag'};
  var FIELDS=['namn','kontakt','telefon','postnr','epost','beskriv','adress','orgname','orgnr','tj','tid'];
  var root=document.getElementById('ampy-form-root');
  if(!root) return;
  /* Idempotens + dubbletskydd: det globala elementet ska placeras EN gång per sida. Skulle Bricks rendera det
     två gånger initieras bara det första; extra instanser göms (annars en synligt tom, trasig formulär-ruta). */
  if(root.getAttribute('data-aof-init')==='1') return;
  root.setAttribute('data-aof-init','1');
  var _dupes=document.querySelectorAll('#ampy-form-root');
  if(_dupes.length>1){for(var _i=1;_i<_dupes.length;_i++){_dupes[_i].setAttribute('aria-hidden','true');_dupes[_i].style.display='none';_dupes[_i].setAttribute('data-aof-init','1');}if(window.console&&console.warn)console.warn('[aof] dubbel #ampy-form-root dold — placera det globala elementet en gång per sida');}
  var qs=new URLSearchParams(location.search);
  var rawPath=(PREVIEW&&qs.get('path'))?qs.get('path'):(root.getAttribute('data-ampy-path')||location.pathname);
  try{rawPath=decodeURIComponent(rawPath);}catch(e){}
  var st={seg:'privat',segTouched:false,open:false,done:false,error:false,sending:false,cfg:null,vals:{},gdpr:false,files:[],lastPayload:null};

  function announce(msg){var l=document.getElementById('aof-live');if(l)l.textContent=msg;}
  function snap(){FIELDS.forEach(function(id){var e=document.getElementById('aof-'+id);if(e)st.vals[id]=e.value;});var g=document.getElementById('aof-gdpr');if(g)st.gdpr=g.checked;}
  function fill(){FIELDS.forEach(function(id){var e=document.getElementById('aof-'+id);if(e&&st.vals[id]!=null)e.value=st.vals[id];});var g=document.getElementById('aof-gdpr');if(g)g.checked=!!st.gdpr;
    var tjEl=document.getElementById('aof-tj');if(tjEl&&st.vals.tj&&tjEl.value!==st.vals.tj)tjEl.value=''; /* val som saknas i nya segmentets lista -> snäpp till platshållaren (dölj ej tyst) */
    var bi=document.getElementById('aof-bilder');if(bi&&st.files&&st.files.length){try{var dt=new DataTransfer();st.files.forEach(function(f){dt.items.add(f);});bi.files=dt.files;}catch(e){}}}

  function fld(label,id,type,opt,req){var im=type==='tel'?' inputmode="tel"':(type==='num'?' inputmode="numeric"':'');var t=type==='num'?'text':type;var ac=(id==='namn'||id==='kontakt')?'name':id==='telefon'?'tel':id==='postnr'?'postal-code':id==='epost'?'email':'off';
    return '<div class="fld"><label class="l" for="aof-'+id+'">'+esc(label)+(opt?' <span class="opt">(valfritt)</span>':'')+'</label><input class="inp" id="aof-'+id+'" type="'+t+'"'+im+(req?' aria-required="true"':'')+' autocomplete="'+ac+'" aria-describedby="aof-'+id+'-h"><p class="help" id="aof-'+id+'-h"></p></div>';}
  function seg(){return '<div class="seg" role="radiogroup" aria-label="Kundtyp">'+['privat','brf','foretag'].map(function(k){var s=st.seg===k;return '<button type="button" role="radio" data-seg="'+k+'" aria-checked="'+s+'" tabindex="'+(s?'0':'-1')+'">'+SEG[k]+'</button>';}).join('')+'</div>';}
  function requiredFields(cfg){
    if(st.seg==='privat') return fld('Namn','namn','text',false,true)+'<div class="row2">'+fld('Telefonnummer','telefon','tel',false,true)+fld('E-post','epost','email',false,true)+'</div><div class="row2">'+fld('Adress','adress','text',false,true)+fld('Postnummer','postnr','num',false,true)+'</div>';
    var useL=(st.seg===cfg.kundtyp)?cfg.orgLabel:null;var nm=st.seg==='brf'?(useL||'Föreningens namn'):(useL||'Företagsnamn');
    return fld(nm,'orgname','text',false,true)+'<div class="row2">'+fld('Kontaktperson','kontakt','text',false,true)+fld('Postnummer','postnr','num',false,true)+'</div><div class="row2">'+fld('Telefonnummer','telefon','tel',false,true)+fld('E-post','epost','email',false,true)+'</div>';
  }
  function serviceSelect(cfg,req){var o=(st.seg==='privat')?PRIVAT_OPTS:(cfg.opts||ORG_OPTS);return '<div class="fld"><label class="l" for="aof-tj">Vad gäller arbetet?</label><select class="inp" id="aof-tj"'+(req?' aria-required="true"':'')+' aria-describedby="aof-tj-h"><option value="">Välj vad det gäller</option>'+o.map(function(x){return '<option>'+esc(x)+'</option>';}).join('')+'</select><p class="help" id="aof-tj-h"></p></div>';}
  function tjanstBlock(cfg){
    var isEFX=!!cfg.forLabel; /* Form 3 (EFX): kundtyp känd av sidan -> ingen segment-väljare; "Elektriker för X"-tagg + "Vad gäller arbetet?" */
    var chip=isEFX?('Elektriker för '+cfg.forLabel):((cfg.ort&&cfg.ortWord)?(cfg.ortWord+' i '+cfg.ort):cfg.tjanstLocked?('Gäller: '+cfg.tjanst):null);
    var h=chip?'<div class="gchip"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12l5 5L20 7"/></svg><span>'+esc(chip)+'</span></div>':'';
    if(cfg.ask&&isEFX)h+=serviceSelect(cfg,true);
    return h;
  }
  function enrich(cfg){var isOrg=(st.seg!=='privat');var svc=(cfg.ask&&!cfg.forLabel)?serviceSelect(cfg,false):'';var org=isOrg?fld('Organisationsnummer','orgnr','num',true):'';var adress=isOrg?fld('Adress','adress','text',true):'';return svc+org+'<div class="fld"><label class="l" for="aof-beskriv">Beskriv uppdraget <span class="opt">(valfritt)</span></label><textarea class="inp" id="aof-beskriv"></textarea></div>'+adress+'<div class="fld"><label class="l" for="aof-tid">Tidsram <span class="opt">(valfritt)</span></label><select class="inp" id="aof-tid"><option value="">Välj tidsram</option><option>Inom 24 timmar</option><option>Inom 72 timmar</option><option>Om 1-2 veckor</option><option>Flexibelt</option></select></div><div class="fld"><label class="l" for="aof-bilder">Bilder <span class="opt">(valfritt)</span></label><label class="upload"><input type="file" id="aof-bilder" accept="image/*" multiple style="position:absolute;left:-9999px"><span aria-hidden="true">Ladda upp bilder</span></label><p class="help" id="aof-bilder-note" style="display:block;opacity:.7">Byter du formulärtyp får du välja bilderna igen.</p></div>';}
  function formCard(cfg){
    return '<div class="card"><h1 class="title">Få kostnadsfri rådgivning!</h1>'+
      '<form novalidate>'+(cfg.forLabel?'':'<div class="group">'+seg()+'</div>')+
      '<div class="group">'+tjanstBlock(cfg)+requiredFields(cfg)+'</div>'+
      '<div class="disc" data-open="'+st.open+'"><button type="button" data-act="toggle" aria-expanded="'+st.open+'">Fler detaljer (valfritt)<svg class="chev ic" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 8l5 5 5-5"/></svg></button><div class="disc-body"><div class="group">'+enrich(cfg)+'</div></div></div>'+
      '<label class="consent"><input type="checkbox" id="aof-gdpr" aria-describedby="aof-gdpr-h"><span>Jag godkänner att Ampy behandlar mina uppgifter enligt <a href="https://ampy.se/integritetspolicy" target="_blank" rel="noopener">integritetspolicyn</a>.</span></label><p class="help" id="aof-gdpr-h">Vi behöver ditt godkännande för att få kontakta dig.</p>'+
      '<input type="text" class="hp" id="aof-company_url" name="company_url" tabindex="-1" autocomplete="off" aria-hidden="true">'+
      '<button type="button" class="btn btn-primary" data-act="submit">Boka rådgivning</button></form></div>';
  }
  function excludeCard(cfg){
    if(cfg.kind==='eljour') return '<div class="noform"><b>Akut elfel?</b><br>Ring oss direkt på <a href="tel:+46102657979">010-265 79 79</a>.</div>';
    return '<div class="noform">Formuläret visas inte på den här sidtypen.</div>';
  }
  function doneCard(){return '<div class="card"><div class="done"><div class="ok"><svg viewBox="0 0 24 24" fill="none" stroke-width="2.4"><path d="M5 12l5 5L20 7"/></svg></div><h2 tabindex="-1">Tack, vi har din förfrågan</h2><p>En behörig elektriker återkommer via telefon.</p></div>'+(PREVIEW&&st.lastPayload?'<div class="payload">PREVIEW — POST till '+esc(ENDPOINT)+':\n'+esc(st.lastPayload)+'</div>':'')+'</div>';}
  function errorCard(){return '<div class="card"><div class="done"><h2 tabindex="-1">Något gick fel</h2><p>Försök igen, eller ring oss på <a href="tel:+46102657979">010-265 79 79</a>.</p><button type="button" class="btn btn-primary" data-act="retry">Försök igen</button></div></div>';}

  function render(){
    if(!root) return;
    snap();
    var cfg;try{cfg=resolve(rawPath);}catch(e){cfg={render:true,kundtyp:'privat',ask:true,vertical:'oklart',source:rawPath};}
    st.cfg=cfg;
    if(cfg.kundtyp&&(cfg.forLabel||!st.segTouched))st.seg=cfg.kundtyp; /* EFX: kundtyp är låst av sidan */
    try{
      if(!cfg.render){root.innerHTML=excludeCard(cfg);return;}
      if(st.error){root.innerHTML=errorCard();var eh=root.querySelector('.done h2');if(eh)eh.focus();return;}
      if(st.done){root.innerHTML=doneCard();var dh=root.querySelector('.done h2');if(dh)dh.focus();announce('Tack, vi har din förfrågan. En behörig elektriker återkommer via telefon.');return;}
      root.innerHTML=formCard(cfg);
      fill();
    }catch(e){root.innerHTML=errorCard();}
  }

  function val(id){var e=document.getElementById('aof-'+id);return e?e.value.trim():'';}
  function validate(){
    var ids=(st.seg==='privat')?['namn','telefon','epost','adress','postnr']:['orgname','kontakt','telefon','epost','postnr'];
    if(st.cfg&&st.cfg.forLabel)ids.push('tj'); /* EFX: "Vad gäller arbetet?" syns i huvudvyn -> obligatorisk */
    var ok=true,first=null;
    var MSG={namn:'Skriv ditt namn.',kontakt:'Skriv kontaktpersonens namn.',telefon:'Skriv ett telefonnummer vi kan nå dig på.',epost:'Skriv en giltig e-postadress.',adress:'Fyll i adressen.',postnr:'Postnummer ska vara fem siffror.',orgname:'Fyll i namnet.',tj:'Välj vad det gäller.'};
    ids.forEach(function(id){var el=document.getElementById('aof-'+id);if(!el)return;var f=el.closest('.fld'),bad=false,v=el.value.trim();
      if(id==='postnr')bad=!/^\d{3}\s?\d{2}$/.test(v);
      else if(id==='telefon')bad=v.replace(/[\s\-()]/g,'').replace(/^\+46/,'0').replace(/\D/g,'').length<7;
      else if(id==='epost')bad=!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v);
      else if(id==='tj')bad=!v;
      else bad=v.length<2;
      var h=document.getElementById('aof-'+id+'-h');if(h)h.textContent=bad?(MSG[id]||''):'';
      if(f)f.classList.toggle('err',bad);el.setAttribute('aria-invalid',bad?'true':'false');if(bad){ok=false;if(!first)first=el;}});
    var gd=document.getElementById('aof-gdpr'),gh=document.getElementById('aof-gdpr-h');
    if(gd){if(!gd.checked){ok=false;gd.setAttribute('aria-invalid','true');if(gh)gh.style.display='block';if(!first)first=gd;}else{gd.setAttribute('aria-invalid','false');if(gh)gh.style.display='none';}}
    if(!ok)announce('Kontrollera de markerade fälten.');
    return {ok:ok,first:first};
  }
  function buildPayload(){var cfg=st.cfg;var tjEl=document.getElementById('aof-tj');var tj=cfg.tjanstLocked?cfg.tjanst:(tjEl?tjEl.value:'');var tid=document.getElementById('aof-tid');var hp=document.getElementById('aof-company_url');var bi=document.getElementById('aof-bilder');
    return {full_name:(st.seg==='privat'?val('namn'):val('kontakt')),phone_e164:toE164(val('telefon')),postal_code:val('postnr').replace(/\D/g,''),email:val('epost'),org_number:(st.seg!=='privat'&&val('orgnr')?val('orgnr').replace(/\D/g,''):null),org_name:(st.seg!=='privat'?val('orgname'):null),kundtyp:st.seg,vertical:cfg.vertical,tjanst_intresse:tj||null,bradska:(tid&&BRADSKA[tid.value])||null,beskrivning:val('beskriv')||null,street_address:val('adress')||null,bilder_count:((bi&&bi.files&&bi.files.length)?bi.files.length:(st.files?st.files.length:0)),kallsida:cfg.source,source:'bricks',source_form:SOURCE_FORM,consent:true,policy_version:POLICY_VERSION,company_url:(hp?hp.value:'')};
  }
  function submit(){
    if(st.sending)return;
    var v=validate();if(!v.ok){if(v.first)v.first.focus();return;}
    var hp=document.getElementById('aof-company_url');if(hp&&hp.value)return; /* honeypot */
    var payload;try{payload=buildPayload();}catch(e){st.error=true;render();return;}
    st.lastPayload=JSON.stringify(payload,null,2);
    if(PREVIEW){st.done=true;render();return;}
    st.sending=true;var btn=root.querySelector('[data-act="submit"]');if(btn){btn.disabled=true;btn.textContent='Skickar…';}
    var ctrl=new AbortController(),to=setTimeout(function(){ctrl.abort();},10000);
    fetch(ENDPOINT,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),signal:ctrl.signal})
      .then(function(r){clearTimeout(to);if(!r.ok)throw new Error('http '+r.status);st.sending=false;st.done=true;render();})
      .catch(function(){clearTimeout(to);st.sending=false;st.error=true;render();});
  }

  root.addEventListener('click',function(e){
    var sb=e.target.closest('[data-seg]');if(sb){st.seg=sb.dataset.seg;st.segTouched=true;render();var a=root.querySelector('.seg [aria-checked="true"]');if(a)a.focus();return;}
    var a=e.target.closest('[data-act]');if(!a)return;var act=a.dataset.act;
    if(act==='toggle'){st.open=!st.open;var d=a.closest('.disc');if(d){d.setAttribute('data-open',st.open);a.setAttribute('aria-expanded',st.open);}else{render();}} /* flippa in-place (CSS sköter visning) -> fokus + valda bilder överlever */
    else if(act==='submit'){submit();}
    else if(act==='retry'){st.error=false;render();}
  });
  root.addEventListener('keydown',function(e){
    var t=e.target;
    /* Enter i ett textfält = submit (implicit submission för tangentbord/skärmläsare). Select/textarea/checkbox exkluderas (saknar .inp eller är ej input). */
    if(e.key==='Enter'&&t&&t.matches&&t.matches('input.inp')){e.preventDefault();submit();return;}
    if(t&&t.getAttribute&&t.getAttribute('role')==='radio'){
      if(['ArrowRight','ArrowLeft','ArrowUp','ArrowDown'].indexOf(e.key)>-1){
        e.preventDefault();var order=['privat','brf','foretag'],i=order.indexOf(st.seg),d=(e.key==='ArrowRight'||e.key==='ArrowDown')?1:-1;
        st.seg=order[(i+d+3)%3];st.segTouched=true;render();var a=root.querySelector('.seg [aria-checked="true"]');if(a)a.focus();
      }else if(e.key==='Home'||e.key==='End'){
        e.preventDefault();var ord=['privat','brf','foretag'];st.seg=(e.key==='Home')?ord[0]:ord[ord.length-1];st.segTouched=true;render();var b=root.querySelector('.seg [aria-checked="true"]');if(b)b.focus();
      }
    }
  });
  /* Bevara valda bilder i state (FileList kan ej återställas via .value efter en render) */
  root.addEventListener('change',function(e){var f=e.target&&e.target.closest&&e.target.closest('#aof-bilder');if(f)st.files=Array.prototype.slice.call(f.files);});
  render();
})();
