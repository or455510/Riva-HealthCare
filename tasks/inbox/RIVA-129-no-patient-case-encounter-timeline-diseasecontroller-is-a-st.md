# RIVA-129: No patient case/encounter timeline — DiseaseController is a static reference catalog only

- **ID:** RIVA-129
- **Title:** No patient case/encounter timeline — DiseaseController is a static reference catalog only
- **Status:** INBOX
- **Risk Level:** LOW
- **Assigned:** UNASSIGNED
- **Reviewer:** UNASSIGNED
- **Allowed Operations:** read, safe-operations
- **Created:** 2026-08-25T01:13:24.975Z
- **Updated:** 2026-08-25T01:13:24.975Z

## Objective
Investigate and scope real per-patient case/encounter tracking

## Business Reason
User cannot show a doctor 'where do I see a patient's case/history' because it does not exist yet

## Technical Requirements
No Case/Encounter/MedicalRecord model; DiseaseController is a shared disease dictionary, not per-patient history

## Acceptance Criteria
Decision + scoped plan recorded
