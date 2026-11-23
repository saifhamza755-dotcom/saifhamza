window.APP_SEED = {
  appName: 'منصة اليمن لتقييم وضمان جودة واعتماد مؤسسات التعليم العالي',
  roles: {
    admin: 'مسؤول النظام التقني',
    central: 'الإدارة المركزية / هيئة الجودة',
    university: 'مسؤول الجودة الجامعي',
    college: 'مسؤول جودة الكلية',
    dean: 'العميد / الجهة المخولة',
    evaluator: 'المقيم الخارجي'
  },
  demoUsers: [
    {id:1, username:'admin', password:'1234', name:'Admin', role:'admin', scope:'النظام بالكامل'},
    {id:2, username:'central', password:'1234', name:'سارة محمد', role:'central', scope:'جميع المؤسسات'},
    {id:3, username:'university', password:'1234', name:'خالد عبدالله', role:'university', scope:'جامعة صنعاء'},
    {id:4, username:'college', password:'1234', name:'محمد أحمد', role:'college', scope:'كلية الهندسة - جامعة صنعاء'},
    {id:5, username:'dean', password:'1234', name:'د. أحمد علي', role:'dean', scope:'كلية الهندسة - جامعة صنعاء'},
    {id:6, username:'evaluator', password:'1234', name:'م. علي حسن', role:'evaluator', scope:'حسب التكليف'},
  ],
  institutions: [
    {id:'u1', type:'جامعة', name:'جامعة صنعاء', parent:null},
    {id:'c1', type:'كلية', name:'كلية الهندسة', parent:'u1'},
    {id:'d1', type:'قسم', name:'قسم تقنية المعلومات', parent:'c1'},
    {id:'p1', type:'برنامج', name:'برنامج تقنية المعلومات', parent:'d1'},
    {id:'d2', type:'قسم', name:'قسم الهندسة المدنية', parent:'c1'},
    {id:'c2', type:'كلية', name:'كلية الطب', parent:'u1'},
    {id:'c3', type:'كلية', name:'كلية الحاسوب', parent:'u1'},
    {id:'u2', type:'جامعة', name:'جامعة تعز', parent:null},
    {id:'u3', type:'جامعة', name:'جامعة عدن', parent:null}
  ],
  standards: [
    {id:1, name:'القيادة والحوكمة', weight:15, indicators:6},
    {id:2, name:'التعليم والتعلم', weight:25, indicators:10},
    {id:3, name:'البحث العلمي', weight:20, indicators:8},
    {id:4, name:'خدمة المجتمع', weight:10, indicators:5},
    {id:5, name:'الموارد والمرافق', weight:10, indicators:6},
    {id:6, name:'الطلبة والخدمات', weight:8, indicators:5},
    {id:7, name:'أعضاء هيئة التدريس', weight:7, indicators:4},
    {id:8, name:'التحسين وضمان الجودة', weight:5, indicators:4}
  ],
  indicators: [
    {id:'1.1', standardId:1, text:'توجد قيادة واضحة ومسؤوليات محددة للجودة.'},
    {id:'1.2', standardId:1, text:'توجد سياسات وإجراءات موثقة لاتخاذ القرار.'},
    {id:'2.1', standardId:2, text:'البرامج الأكاديمية موصوفة ومحدثة.'},
    {id:'2.2', standardId:2, text:'توجد آليات لمتابعة جودة التعليم والتعلم.'},
    {id:'2.3', standardId:2, text:'توجد آليات موثقة لمراجعة البرامج الأكاديمية وتحديثها دوريًا.'},
    {id:'3.1', standardId:3, text:'توجد خطة واضحة لدعم البحث العلمي.'},
    {id:'3.2', standardId:3, text:'يتم متابعة مخرجات البحث العلمي وتحليلها.'},
    {id:'4.1', standardId:4, text:'توجد أنشطة موثقة لخدمة المجتمع.'},
    {id:'5.1', standardId:5, text:'المعامل والمرافق ملائمة لاحتياجات البرامج.'},
    {id:'8.1', standardId:8, text:'توجد آلية دورية للتحسين المستمر.'}
  ],
  cycles: [
    {id:'cy2026', name:'دورة التقييم 2026', standardsVersion:'2.0', status:'نشطة', start:'2026-08-01', end:'2026-12-31'}
  ],
  initialSelfScores: {'1.1':4,'1.2':3,'2.1':3,'2.2':3,'2.3':3,'3.1':3,'3.2':2,'4.1':3,'5.1':2,'8.1':3},
  initialExternalScores: {'1.1':4,'1.2':3,'2.1':3,'2.2':2,'2.3':3,'3.1':2,'3.2':2,'4.1':3,'5.1':2,'8.1':3},
  collegeReport: {status:'مسودة', note:'', completeness:68, submittedAt:null, deanDecision:null},
  universityReport: {status:'قيد الإعداد', readyColleges:5, totalColleges:8, sentAt:null},
  accreditation: {status:'لم يرسل', decision:null, note:''},
  evaluators: [
    {id:'e1', name:'علي حسن', employer:'جامعة تعز', conflict:false},
    {id:'e2', name:'سمير محمد', employer:'جامعة عدن', conflict:true, conflictReason:'مستشار سابق للمؤسسة'},
    {id:'e3', name:'فاطمة أحمد', employer:'جامعة إب', conflict:false}
  ],
  teams: [
    {id:'t1', name:'الفريق الأول', institution:'جامعة صنعاء', members:['e1','e3'], visit:'2026-10-15', status:'مجدولة'}
  ],
  visits: [
    {id:'v1', institution:'جامعة صنعاء', team:'الفريق الأول', date:'2026-10-15', time:'09:00', type:'زيارة اعتماد', status:'مجدولة'}
  ],
  improvementActions: [
    {id:'i1', action:'تحديث سياسة مراجعة البرامج', owner:'عميد الكلية', due:'2026-09-30', progress:80, evidence:2, status:'جارٍ'},
    {id:'i2', action:'تطوير آلية متابعة البحث العلمي', owner:'مسؤول البحث', due:'2026-10-15', progress:45, evidence:1, status:'جارٍ'},
    {id:'i3', action:'تحديث تجهيزات المعامل', owner:'مدير المرافق', due:'2026-11-01', progress:20, evidence:0, status:'متأخر'}
  ],
  notifications: [
    {id:1, role:'college', text:'لديك مؤشرات غير مكتملة في التقويم الذاتي.', read:false},
    {id:2, role:'dean', text:'يوجد تقرير كلية بانتظار المراجعة.', read:false},
    {id:3, role:'central', text:'يجب معالجة تعارض مصالح قبل اعتماد فريق التقييم.', read:false},
    {id:4, role:'evaluator', text:'موعد الزيارة الميدانية 15 أكتوبر 2026.', read:false}
  ]
};
