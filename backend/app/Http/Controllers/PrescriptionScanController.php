<?php

namespace App\Http\Controllers;

use App\Models\Medication;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PatientDoctorRelationship;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RIVA-130 (product-owner request, recorded 2026-08-25): real photo-based
 * prescription capture. A doctor photographs a written prescription;
 * scan() sends it to the SAME Gemini integration already configured and
 * working for the AI assistant chat (AiAssistantController — no new API
 * key, no new infrastructure, no Tesseract/OCR install needed on the real
 * server) and asks it to extract a structured medication list. Nothing is
 * saved at this point — the extracted list is handed back to the doctor to
 * review and correct (handwriting reading is inherently unreliable; the
 * prompt explicitly tells the model not to invent medications it can't
 * read). Only register() — called with the doctor's REVIEWED/corrected
 * list — actually creates Medication rows. Every created medication then
 * flows through the existing medication-reminder/adherence system
 * automatically (MedicationAdherenceService, MedicationController::take/
 * snooze/missed) — that engine already works generically off the
 * `medications` table for any patient, no extra wiring needed here.
 *
 * "Nearest pharmacy" is intentionally NOT computed here: register()
 * attempts a best-effort name match against the real `medicines` catalog
 * and returns the matched id (or null, honestly, when no catalog entry
 * matches) so the frontend can call the ALREADY-EXISTING, already-tested
 * GET /medicines/{medicine}/availability (PharmacyController::availability())
 * — which itself only returns pharmacy branches with a real, admin-entered
 * availability row. See that migration's docblock: there is no seeded
 * pharmacy/medicine data anywhere, so an empty result is expected and
 * honest, not a bug.
 */
class PrescriptionScanController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function scan(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

        $apiKey = config('services.gemini.key');
        if (! $apiKey) {
            Log::error('Gemini API key missing for prescription scan endpoint', ['env_name' => 'GEMINI_API_KEY']);

            return $this->error(
                'Prescription scanning is not configured on this server yet (missing Gemini API key). You can still add medications manually below.',
                503
            );
        }

        $file = $request->file('image');
        $base64 = base64_encode((string) file_get_contents($file->getRealPath()));
        $mimeType = $file->getMimeType() ?: 'image/jpeg';

        $model = config('services.gemini.model', 'gemini-2.5-flash');
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
        $httpOptions = ['verify' => (bool) config('services.gemini.verify_ssl', true)];

        // RIVA-130 follow-up (product-owner feedback, 2026-08-25): doctors'
        // handwriting is notoriously hard to read, and the model was
        // misreading prescriptions. Strengthened with: (1) explicit domain
        // knowledge of standard Rx shorthand so ambiguous strokes can be
        // disambiguated the way a pharmacist would, not read letter-by-letter;
        // (2) an instruction to expand that shorthand into the SAME plain-
        // English phrasing the review form's dropdown presets use (e.g.
        // "bid" -> "Twice daily"), so a correct read lands directly on a
        // one-tap dropdown option instead of free text; (3) a per-medication
        // confidence rating so the frontend can flag uncertain reads for
        // extra scrutiny — this is about surfacing uncertainty more
        // usefully, not pretending to be more certain than the image
        // actually allows.
        $prompt = "You are an experienced pharmacist reading a photograph of a doctor's prescription (handwritten or "
            ."printed) for a clinic patient. Doctors' handwriting is often fast and abbreviated — read it the way a "
            ."pharmacist would, using standard prescription shorthand, not letter-by-letter: "
            ."od/qd = once daily; bid/bd = twice daily; tid/t.d.s. = three times daily; qid/q.d.s. = four times daily; "
            ."q4h/q6h/q8h/q12h = every 4/6/8/12 hours; prn = as needed; ac = before meals; pc = after meals; "
            ."hs/qhs = at bedtime; stat = immediately. Dosage units are almost always mg, ml, g, mcg, IU, or a plain "
            ."tablet/capsule count. Cross-check any name you're unsure of against real, commonly prescribed drug "
            ."names (brand or generic) rather than transcribing an implausible string literally — but if no plausible "
            ."real medication fits, give your best literal reading instead of guessing a different drug.\n\n"
            ."Extract every distinct medication listed. For each one, provide: "
            ."name; dosage (e.g. \"500mg\"); frequency — if you recognize shorthand, output the EXPANDED plain-English "
            ."form exactly as spelled here: \"Once daily\", \"Twice daily\", \"Three times daily\", \"Four times daily\", "
            ."\"Every 4 hours\", \"Every 6 hours\", \"Every 8 hours\", \"Every 12 hours\", \"As needed\", \"Before meals\", "
            ."\"After meals\", \"At bedtime\" — and only fall back to a literal transcription when it doesn't match any "
            ."of those; duration if mentioned (e.g. \"7 days\"); any special instructions (e.g. \"after meals\"); and a "
            ."confidence rating of \"high\", \"medium\", or \"low\" for how sure you are of the NAME specifically (the "
            ."field most critical to get right and hardest to read).\n\n"
            ."If a field is genuinely unclear even with this reasoning, still give your best reading, mark that "
            ."medication's confidence honestly, and note the specific uncertainty in scan_notes (e.g. \"third line "
            ."dosage unclear, could be 250mg or 750mg\"). Never invent a medication that is not visibly written on the "
            ."prescription — if you cannot read the prescription at all, return an empty medications array and explain "
            ."why in scan_notes. A doctor will REVIEW and correct every field before anything is saved, so it is "
            ."always fine to mark low confidence rather than guess with false certainty.";

        try {
            $response = Http::timeout(30)
                ->connectTimeout(8)
                ->withOptions($httpOptions)
                ->acceptJson()
                ->asJson()
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($endpoint, [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                            ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'medications' => [
                                    'type' => 'ARRAY',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'name' => ['type' => 'STRING'],
                                            'dosage' => ['type' => 'STRING'],
                                            'frequency' => ['type' => 'STRING'],
                                            'duration' => ['type' => 'STRING'],
                                            'instructions' => ['type' => 'STRING'],
                                            'confidence' => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low']],
                                        ],
                                        'required' => ['name', 'confidence'],
                                    ],
                                ],
                                'scan_notes' => ['type' => 'STRING'],
                            ],
                            'required' => ['medications'],
                        ],
                    ],
                ]);
        } catch (Throwable $exception) {
            Log::warning('Gemini prescription scan connection failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return $this->error('Unable to reach the scanning service. You can add medications manually below.', 502);
        }

        if ($response->failed()) {
            Log::warning('Gemini prescription scan request failed', [
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return $this->error('Scanning this image failed. You can add medications manually below.', 502);
        }

        $rawText = data_get($response->json(), 'candidates.0.content.parts.0.text');
        $parsed = $rawText ? json_decode((string) $rawText, true) : null;

        if (! is_array($parsed) || ! isset($parsed['medications']) || ! is_array($parsed['medications'])) {
            Log::warning('Gemini prescription scan returned unparseable output', ['raw' => $rawText]);

            return $this->error('Could not read structured results from this scan. You can add medications manually below.', 502);
        }

        $medications = collect($parsed['medications'])
            ->filter(fn ($m) => is_array($m) && filled($m['name'] ?? null))
            ->map(fn ($m) => [
                'name' => (string) $m['name'],
                'dosage' => (string) ($m['dosage'] ?? ''),
                'frequency' => (string) ($m['frequency'] ?? ''),
                'duration' => (string) ($m['duration'] ?? ''),
                'instructions' => (string) ($m['instructions'] ?? ''),
                // RIVA-130 follow-up: lets the review UI flag a reading the
                // model itself wasn't confident about, so the doctor knows
                // exactly which fields deserve a closer look against the
                // original prescription rather than treating every row as
                // equally reliable.
                'confidence' => in_array($m['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $m['confidence'] : 'medium',
            ])
            ->values();

        return $this->success(
            [
                'medications' => $medications,
                'scan_notes' => $parsed['scan_notes'] ?? null,
            ],
            $medications->isEmpty()
                ? 'No medications could be read from this image — you can add them manually below.'
                : 'Scan complete — please review and correct before saving.'
        );
    }

    /**
     * Only this method ever writes a Medication row — always from whatever
     * list the doctor actually submits (the reviewed/corrected scan
     * results, or manually-typed rows, or a mix of both; scan() and
     * register() are deliberately independent, so a failed/skipped scan
     * never blocks manual entry).
     */
    public function register(Request $request)
    {
        $doctor = $request->user()->doctor;
        abort_unless($doctor, 403);

        $data = $request->validate([
            'patient_id' => ['required', 'exists:patients,id'],
            'medications' => ['required', 'array', 'min:1'],
            'medications.*.name' => ['required', 'string', 'max:255'],
            'medications.*.dosage' => ['nullable', 'string', 'max:255'],
            'medications.*.frequency' => ['nullable', 'string', 'max:255'],
            'medications.*.duration' => ['nullable', 'string', 'max:255'],
            'medications.*.instructions' => ['nullable', 'string', 'max:2000'],
            'medications.*.start_date' => ['nullable', 'date'],
            'medications.*.end_date' => ['nullable', 'date', 'after_or_equal:medications.*.start_date'],
        ]);

        $this->ensureAssignedPatient($doctor->id, (int) $data['patient_id']);

        $created = collect($data['medications'])->map(function (array $item) use ($data) {
            $matchedMedicine = Medicine::query()
                ->where('is_active', true)
                ->where(function ($q) use ($item) {
                    $q->where('name', 'like', $item['name'].'%')
                        ->orWhere('generic_name', 'like', $item['name'].'%');
                })
                ->first();

            $medication = Medication::create([
                'patient_id' => $data['patient_id'],
                'name' => $item['name'],
                // RIVA-132 follow-up: persist the match (previously computed
                // but discarded — only returned in this one response, lost
                // the moment the doctor navigated away) so the patient's own
                // medication list can re-offer "Find nearby pharmacies"
                // every time they view it, not only right now.
                'medicine_id' => $matchedMedicine?->id,
                'dosage' => filled($item['dosage'] ?? null) ? $item['dosage'] : 'As directed',
                'frequency' => filled($item['frequency'] ?? null) ? $item['frequency'] : 'daily',
                'instructions' => $this->combineInstructions($item),
                'start_date' => $item['start_date'] ?? today()->toDateString(),
                'end_date' => $item['end_date'] ?? null,
                'is_active' => true,
            ]);

            return [
                'medication' => $this->serializeMedication($medication),
                'matched_medicine_id' => $matchedMedicine?->id,
                'matched_medicine_name' => $matchedMedicine?->name,
            ];
        })->values();

        $patient = Patient::with('user')->find($data['patient_id']);
        if ($patient?->user) {
            $this->notificationService->create(
                $patient->user,
                'medication.prescribed',
                'New Medication Prescribed',
                'Dr. '.$request->user()->name.' added '.$created->count().' medication(s) to your plan.',
                [
                    'patient_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'source' => 'prescription_scan',
                ],
                '/add-new-medication',
                'medications'
            );
        }

        return $this->success(['registered' => $created], $created->count().' medication(s) registered.', 201);
    }

    private function combineInstructions(array $item): ?string
    {
        $parts = array_filter([
            filled($item['duration'] ?? null) ? "Duration: {$item['duration']}" : null,
            $item['instructions'] ?? null,
        ]);

        $combined = trim(implode(' — ', $parts));

        return $combined === '' ? null : $combined;
    }

    private function ensureAssignedPatient(int $doctorId, int $patientId): void
    {
        abort_unless(
            PatientDoctorRelationship::where('doctor_id', $doctorId)
                ->where('patient_id', $patientId)
                ->whereIn('status', ['active', 'accepted'])
                ->exists(),
            403
        );
    }

    private function serializeMedication(Medication $medication): array
    {
        return [
            'id' => $medication->id,
            'patient_id' => $medication->patient_id,
            'name' => $medication->name,
            'medicine_id' => $medication->medicine_id,
            'dosage' => $medication->dosage,
            'frequency' => $medication->frequency,
            'instructions' => $medication->instructions,
            'start_date' => optional($medication->start_date)->toDateString(),
            'end_date' => optional($medication->end_date)->toDateString(),
            'is_active' => $medication->is_active,
        ];
    }
}
