export type DiseaseSlug = 'heart-disease' | 'diabetes' | 'hypertension' | 'cancer';

export interface DiseaseSection {
  title: string;
  body: string;
}

export interface DiseaseImageCard extends DiseaseSection {
  image: string;
  alt: string;
}

export interface DiseaseDetail {
  slug: DiseaseSlug;
  title: string;
  subtitle: string;
  label: string;
  badgeOne: string;
  badgeTwo: string;
  mainImage: string;
  gallery: DiseaseImageCard[];
  highlightTitle: string;
  highlightText: string;
  quickCards: DiseaseSection[];
  symptoms: string[];
  carePlan: string[];
  warningSigns: string[];
}

const asset = (name: string) => `/disease-details/${name}`;

export const DISEASE_DETAILS: Record<DiseaseSlug, DiseaseDetail> = {
  'heart-disease': {
    slug: 'heart-disease',
    label: 'MEDICAL REFERENCE',
    title: 'The Comprehensive Guide to Heart Disease',
    subtitle: 'Heart disease includes coronary artery disease, heart failure, arrhythmia, and valve problems. Riva helps patients and caregivers track symptoms, medications, reports, and follow-up alerts.',
    badgeOne: 'Cardiology',
    badgeTwo: 'ECG Monitor',
    mainImage: asset('heart-main.jpg'),
    gallery: [
      { title: 'ECG & Rhythm Tracking', body: 'Cardiology visuals help patients connect symptoms with heartbeat and ECG follow-up.', image: asset('heart-visual.jpg'), alt: 'Heart rhythm and ECG medical illustration' },
      { title: 'Cardiology Consultation', body: 'Doctor follow-up supports medication review, reports, and symptom escalation.', image: asset('heart-doctor.jpg'), alt: 'Doctor discussing heart health with patient' },
      { title: 'Risk Awareness', body: 'Lifestyle, pressure, cholesterol, and family history all affect cardiovascular risk.', image: asset('heart-risk.jpg'), alt: 'Heart disease risk medical visual' },
    ],
    highlightTitle: 'Coronary Artery Disease',
    highlightText: 'The arteries that supply blood to the heart become narrowed, causing chest pain, shortness of breath, or heart attack risk.',
    quickCards: [
      { title: 'Heart Failure', body: 'The heart becomes too weak to pump enough blood. Patients may feel tired, breathless, or notice swelling in legs.' },
      { title: 'Arrhythmia', body: 'The heart beats too fast, too slow, or irregularly. It can feel like fluttering, pounding, or pauses.' },
      { title: 'Heart Valve Disease', body: 'Valves that control blood flow inside the heart stop working properly, affecting circulation.' },
    ],
    symptoms: ['Chest pain or pressure', 'Shortness of breath', 'Fast or irregular heartbeat', 'Swelling in legs or feet', 'Extreme fatigue'],
    carePlan: ['Take heart medication on time', 'Track blood pressure and pulse', 'Upload ECG/lab reports', 'Contact doctor if symptoms increase'],
    warningSigns: ['Severe chest pain', 'Fainting', 'Sudden breathing difficulty', 'Blue lips or severe weakness'],
  },
  diabetes: {
    slug: 'diabetes',
    label: 'PATIENT GUIDE',
    title: 'Guide for Diabetes Patients and Caregivers',
    subtitle: 'Diabetes happens when the body cannot properly regulate blood sugar. Riva supports daily monitoring, medication adherence, lab uploads, and caregiver alerts.',
    badgeOne: 'Diabetes',
    badgeTwo: 'Glucose Monitor',
    mainImage: asset('diabetes-glucose-monitor.png'),
    gallery: [
      { title: 'Blood Sugar Testing', body: 'Daily glucose checks help patients and doctors spot trends before symptoms become urgent.', image: asset('diabetes-glucose-monitor.png'), alt: 'Patient checking blood glucose with doctor support' },
      { title: 'Lab Follow-up', body: 'HbA1c and lab uploads keep long-term progress visible between appointments.', image: asset('diabetes-lab.jpg'), alt: 'Diabetes laboratory testing concept' },
      { title: 'Sugar Intake Awareness', body: 'Meal choices and sugar intake are part of the daily care plan for stable readings.', image: asset('diabetes-main.jpg'), alt: 'Sugar and diabetes medical awareness illustration' },
    ],
    highlightTitle: 'Blood Sugar Control',
    highlightText: 'Glucose is the body\'s main energy source, but insulin is needed to move it into cells. Poor control can damage nerves, kidneys, eyes, and heart.',
    quickCards: [
      { title: 'Type 1 Diabetes', body: 'The body cannot produce insulin and usually needs lifelong insulin therapy.' },
      { title: 'Type 2 Diabetes', body: 'The body resists insulin or does not make enough. Lifestyle and medication are both important.' },
      { title: 'Prediabetes', body: 'Blood sugar is higher than normal but not yet diabetes. Early action can prevent progression.' },
    ],
    symptoms: ['Frequent urination', 'Excessive thirst', 'Unexplained weight loss', 'Blurred vision', 'Slow wound healing'],
    carePlan: ['Monitor blood glucose', 'Take medication or insulin on schedule', 'Follow meal plan', 'Upload HbA1c and lab results'],
    warningSigns: ['Very low sugar symptoms', 'Confusion or fainting', 'Persistent vomiting', 'Very high sugar reading'],
  },
  hypertension: {
    slug: 'hypertension',
    label: 'PATIENT GUIDE',
    title: 'Guide for High Blood Pressure Patients',
    subtitle: 'High blood pressure can silently damage blood vessels, heart, brain, and kidneys. Riva helps patients record readings and alerts caregivers when follow-up is needed.',
    badgeOne: 'Pressure',
    badgeTwo: 'BP Monitor',
    mainImage: asset('hypertension-blood-pressure-monitor.png'),
    gallery: [
      { title: 'Blood Pressure Checks', body: 'Regular home and clinic readings make changes easier to catch and share.', image: asset('hypertension-blood-pressure-monitor.png'), alt: 'Elderly patient using blood pressure monitor with doctor' },
      { title: 'Heart & Pressure Impact', body: 'High pressure strains vessels and the heart, so ECG and symptom context matters.', image: asset('hypertension-ecg.jpg'), alt: 'Blood pressure and ECG medical illustration' },
      { title: 'Doctor Consultation', body: 'Medication review and lifestyle coaching reduce long-term complications.', image: asset('hypertension-check.jpg'), alt: 'Doctor checking patient blood pressure' },
    ],
    highlightTitle: 'Primary Hypertension',
    highlightText: 'The most common type. It develops gradually over years and is linked to lifestyle, age, stress, weight, and genetics.',
    quickCards: [
      { title: 'Secondary Hypertension', body: 'Caused by another condition such as kidney disease, hormonal disorders, or some medications.' },
      { title: 'Silent Risk', body: 'Many patients do not feel symptoms, so regular measurement is important.' },
      { title: 'Lifestyle Support', body: 'Salt reduction, activity, sleep, and medication adherence can lower complications.' },
    ],
    symptoms: ['Headache', 'Dizziness', 'Chest discomfort', 'Shortness of breath', 'Vision problems'],
    carePlan: ['Measure pressure regularly', 'Take medication on time', 'Reduce salt intake', 'Share readings with doctor'],
    warningSigns: ['Severe headache', 'Chest pain', 'Weakness on one side', 'Severe shortness of breath'],
  },
  cancer: {
    slug: 'cancer',
    label: 'PATIENT GUIDE',
    title: 'Guide for Cancer Patients and Caregivers',
    subtitle: 'Cancer is a group of diseases where abnormal cells grow uncontrollably. Riva helps organize reports, medications, daily check-ins, and communication with doctors.',
    badgeOne: 'Cancer',
    badgeTwo: 'Care Monitor',
    mainImage: asset('cancer-main.jpg'),
    gallery: [
      { title: 'Oncology Follow-up', body: 'Treatment plans depend on diagnosis, stage, scans, lab results, and patient condition.', image: asset('cancer-main.jpg'), alt: 'Oncology medical care concept' },
      { title: 'Medical Testing', body: 'Lab and imaging reports should stay organized for safer follow-up decisions.', image: asset('cancer-immune.jpg'), alt: 'Cancer immune and lab testing concept' },
      { title: 'Caregiver Support', body: 'Daily symptom notes and alerts help families stay involved without confusion.', image: asset('cancer-neuron.jpg'), alt: 'Cancer care support medical illustration' },
    ],
    highlightTitle: 'Personalized Follow-up',
    highlightText: 'Cancer care depends on disease type, stage, treatment plan, and patient condition. Consistent monitoring supports safer treatment.',
    quickCards: [
      { title: 'Breast Cancer', body: 'Common worldwide and often detected through lumps, imaging, or changes in breast tissue.' },
      { title: 'Lung Cancer', body: 'Often linked to smoking but can affect non-smokers. Early symptoms can be mild.' },
      { title: 'Leukemia', body: 'Cancer of blood and bone marrow that affects healthy blood cell production.' },
    ],
    symptoms: ['Unexplained weight loss', 'Persistent fatigue', 'Unusual lumps', 'Long-lasting pain', 'Repeated fever or infections'],
    carePlan: ['Upload scans and lab reports', 'Track medication and side effects', 'Record symptoms daily', 'Keep doctor/caregiver updated'],
    warningSigns: ['Severe bleeding', 'High fever', 'Sudden severe pain', 'Severe weakness or confusion'],
  },
};

export function normalizeDiseaseSlug(value: string | null | undefined): DiseaseSlug {
  const slug = (value || '').toLowerCase().trim();
  if (slug === 'heart' || slug === 'cardiology') return 'heart-disease';
  if (slug === 'hypertension' || slug === 'pressure' || slug === 'blood-pressure' || slug === 'high-blood-pressure') return 'hypertension';
  if (slug === 'diabetes' || slug === 'sugar') return 'diabetes';
  if (slug === 'cancer' || slug === 'oncology') return 'cancer';
  return 'heart-disease';
}
