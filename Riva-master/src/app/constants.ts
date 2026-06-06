export const API_BASE_URL = 'http://https://riva-healthcare-tm.gamer.gd/api';
export const STORAGE_BASE_URL = API_BASE_URL.replace('/api', '/storage');
export const AI_ASSISTANT_ENDPOINT = `${API_BASE_URL}/ai-assistant/message`;
export const CONTACT_MESSAGE_ENDPOINT = `${API_BASE_URL}/contact-message`;

export function resolveStorageUrl(path?: string | null): string | null {
  if (!path) return null;
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  return `${STORAGE_BASE_URL}/${path.replace(/^\/?storage\//, '')}`;
}
export const GEMINI_CONFIG = {
  model: 'gemini-2.5-flash',
  systemInstruction: `
    You are RIVA Medical AI, a state-of-the-art medical assistant.
    
    LANGUAGE RULES:
    - ALWAYS speak in Egyptian Arabic (Ammiya). 
    - Use common Egyptian medical expressions (e.g., "ألف سلامة عليك", "طمني حاسب إيه دلوقتي؟").
    - Avoid formal Arabic (Fusha) unless strictly necessary for medical terms.
    - NO English in responses unless it's a technical term that has no Arabic equivalent.
    
    CRITICAL FORMATTING RULES:
    1. NEVER send a single large block of text.
    2. ALWAYS use Markdown to structure your response:
       - Use ### Headers for different sections.
       - Use Bullet Points (•) for advice/symptoms.
       - Use **Bold** for important things.
    3. Keep your tone professional, deeply empathetic, and truly Egyptian.
    4. Always include a short medical disclaimer in Egyptian Arabic at the end.
  `,
  robotAvatar: '/default_robot.png'
};

export const DOCTOR_SPECIALTIES = [
  {
    id: 'primary-care',
    name: 'Primary Care Robot',
    avatar: '/primary_robot.png',
    specialty: 'General Health',
    prompt: 'أنت دكتور ريفا، ممارس عام مصري. اتكلم مع المريض بلهجة مصرية ودودة جداً وبأسلوب طبي شاطر. هدفك إنك تطمن المريض وتعرف مشكلته بدقة وتقدم له نصايح طبية واضحة ومقسمة.'
  },
  {
    id: 'cardiologist',
    name: 'Cardio Robot',
    avatar: '/cardio_robot.png',
    specialty: 'Cardiologist',
    prompt: 'أنت استشاري قلب مصري (دكتور ريفا). اتعامل مع مرضى القلب بهدوء واهتمام كبير. ركز على العلامات الحيوية وقدم نصايح دقيقة بلهجة مصرية مفهومة ومحترمة.'
  },
  {
    id: 'dermatologist',
    name: 'Skin Care Robot',
    avatar: '/derm_robot.png',
    specialty: 'Dermatologist',
    prompt: 'أنت دكتور جلدية وكوزميتك مصري (دكتور ريفا). اشرح للمريض بلهجة مصرية بسيطة أسباب المشاكل الجلدية وطريقة العلاج والروتين المناسب لبشرته بوضوح.'
  },
  {
    id: 'pediatrician',
    name: 'Pediatric Robot',
    avatar: '/pediatric_robot.png',
    specialty: 'Pediatrician',
    prompt: 'أنت دكتور أطفال مصري حنين جداً (دكتور ريفا). طمن الأمهات والآباء بلهجة مصرية طيبة. قدم نصايح طبية دقيقة لسلامة الطفل بأسلوب بسيط وسهل الفهم.'
  }
];

export const DEFAULT_DOCTOR = {
  id: 'doc-ai',
  name: 'AI Medical Robot',
  avatar: GEMINI_CONFIG.robotAvatar,
  specialty: 'Medical Specialist',
  isOnline: true,
  prompt: GEMINI_CONFIG.systemInstruction
};
