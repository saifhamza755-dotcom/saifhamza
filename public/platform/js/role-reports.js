/* Downloadable role-scoped Word reports. This reads the local demo state only. */
(()=>{
  const KEY='YemenQA_document_v3', SESSION=KEY+'_session';
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const download=(html,file)=>{const a=document.createElement('a'),u=URL.createObjectURL(new Blob(['\ufeff'+html],{type:'application/msword'}));a.href=u;a.download=file;a.click();setTimeout(()=>URL.revokeObjectURL(u),1000);};
  const table=(head,rows)=>`<table border="1" cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%"><thead><tr>${head.map(x=>`<th style="background:#eaf3fb">${esc(x)}</th>`).join('')}</tr></thead><tbody>${rows.map(r=>`<tr>${r.map(x=>`<td>${esc(x)}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  document.addEventListener('click',e=>{
    const b=e.target.closest('[data-action="role-report"]'); if(!b)return;
    e.preventDefault(); e.stopImmediatePropagation();
    let s; try{s=JSON.parse(localStorage.getItem(KEY));}catch(_){return;} const u=s?.users?.find(x=>x.id===Number(sessionStorage.getItem(SESSION))); if(!u)return;
    const cy=s.cycles?.find(x=>x.status==='نشطة')||s.cycles?.[0], uni=id=>s.institutions.find(x=>x.id===id)?.name||'—', college=id=>s.institutions.find(x=>x.id===id)?.name||'—'; if(!cy)return;
    const kind=b.dataset.id, uniId=u.universityId||cy.universityIds?.[0], file=s.universityFiles?.[cy.id+'|'+uniId]||{}, colleges=(cy.collegeIds||[]).filter(id=>s.institutions.some(x=>x.id===id&&x.parent===uniId));
    let title, rows, note, filename;
    if(kind==='college'&&['college','dean'].includes(u.role)){
      const coId=u.collegeId, report=s.collegeFiles?.[cy.id+'|'+coId]||{scores:{},evidence:[],status:'مسودة'}, inds=cy.standards?.indicators||[];
      title='تقرير الكلية'; rows=inds.map(i=>[i.text,report.scores?.[i.id]??'غير مقيم',report.evidence?.filter(x=>x.indicatorId===i.id).length||0]); note=`حالة التقرير: ${report.status} — ملاحظات التقرير: ${report.note||'لا توجد ملاحظات'}`; filename='college-quality-report.doc';
      download(`<html dir="rtl"><head><meta charset="utf-8"></head><body style="font-family:Arial;padding:28px"><h1>${title}</h1><p>الجامعة: ${esc(uni(uniId))}</p><p>الكلية: ${esc(college(coId))}</p><p>الدورة: ${esc(cy.name)}</p>${table(['المؤشر','الدرجة من 4','عدد الأدلة'],rows)}<p>${esc(note)}</p></body></html>`,filename); return;
    }
    if(kind==='external'&&['evaluator','central'].includes(u.role)){
      const ex=file.external||{scores:{},status:'مسودة'}, inds=cy.standards?.indicators||[];
      title='تقرير التقييم الخارجي'; rows=colleges.flatMap(co=>inds.map(i=>[college(co),i.text,ex.scores?.[co+'|'+i.id]??'غير مقيم',ex.comments?.[co+'|'+i.id]||'—'])); note=`الحالة: ${ex.status}\nنقاط القوة: ${ex.strengths||'—'}\nنقاط الضعف: ${ex.weaknesses||'—'}\nالتوصيات: ${ex.recommendations||'—'}`; filename='external-evaluation-report.doc';
      download(`<html dir="rtl"><head><meta charset="utf-8"></head><body style="font-family:Arial;padding:28px"><h1>${title}</h1><p>الجامعة: ${esc(uni(uniId))}</p><p>الدورة: ${esc(cy.name)}</p>${table(['الكلية','المؤشر','الدرجة','ملاحظة المقيم'],rows)}<pre style="white-space:pre-wrap;font-family:Arial">${esc(note)}</pre></body></html>`,filename);
    }
  },true);
})();
