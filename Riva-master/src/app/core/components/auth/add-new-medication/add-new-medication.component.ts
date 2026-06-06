import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { HttpClient, HttpClientModule, HttpHeaders } from '@angular/common/http';
import { finalize } from 'rxjs';
import { API_BASE_URL } from '../../../../constants';
import { SidebarComponent } from '../../../../components/sidebar';

interface Medication {
  id?: number;
  name?: string;
  drug_name: string;
  medicine_name?: string;
  dosage: string;
  schedule_time: string;
  reminder_time?: string;
  frequency: string;
  notes: string;
  instructions?: string;
  start_date?: string;
  end_date?: string;
}

interface MedicalRecord {
  id?: number;
  title: string;
  date: string;
  file?: File;
  file_preview?: string;
  file_type?: string;
}

@Component({
  selector: 'app-add-new-medication',
  standalone: true,
  imports: [RouterModule, CommonModule, FormsModule, HttpClientModule, SidebarComponent],
  templateUrl: './add-new-medication.component.html',
})
export class AddNewMedicationComponent implements OnInit {

  private apiUrl = `${API_BASE_URL}/medications`;

  medications: Medication[] = [];
  medicalRecords: MedicalRecord[] = [];

  isLoading = false;
  isSaving = false;
  errorMessage = '';
  successMessage = '';

  newMedication: Medication = {
    drug_name: '',
    dosage: '',
    schedule_time: '',
    frequency: 'daily',
    notes: '',
  };

  newRecord: MedicalRecord = {
    title: '',
    date: ''
  };

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.loadMedications();
  }

  private getToken(): string {
    return localStorage.getItem('token') || '';
  }

  private getHeaders(): HttpHeaders {
    return new HttpHeaders({
      Authorization: `Bearer ${this.getToken()}`,
      Accept: 'application/json',
      'Content-Type': 'application/json'
    });
  }

  loadMedications(): void {
    this.isLoading = true;
    this.http.get<any>(this.apiUrl, { headers: this.getHeaders() })
    .pipe(finalize(() => this.isLoading = false))
    .subscribe({
      next: (res) => this.medications = (res?.data || res || []).map((med: any) => this.mapMedication(med)),
      error: (err) => this.errorMessage = this.apiErrorMessage(err, 'Failed to load medications')
    });
  }

  // معالجة اختيار الملف (صورة أو PDF)
  onFileSelected(event: any): void {
    const file: File = event.target.files[0];
    if (file) {
      this.newRecord.file = file;
      this.newRecord.file_type = file.type;
      
      // إنشاء معاينة إذا كانت صورة
      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = (e: any) => this.newRecord.file_preview = e.target.result;
        reader.readAsDataURL(file);
      } else {
        this.newRecord.file_preview = undefined; // للـ PDF لا نحتاج معاينة صورة
      }
    }
  }

  saveMedication(): void {
    this.errorMessage = '';
    this.successMessage = '';

    if (!this.newMedication.drug_name?.trim() || !this.newMedication.dosage?.trim()) {
      this.errorMessage = 'Please fill required fields';
      return;
    }

    const payload = {
      name: this.newMedication.drug_name.trim(),
      drug_name: this.newMedication.drug_name.trim(),
      medicine_name: this.newMedication.drug_name.trim(),
      dosage: this.newMedication.dosage.trim(),
      schedule_time: this.newMedication.schedule_time || null,
      reminder_time: this.newMedication.schedule_time || null,
      frequency: this.newMedication.frequency || 'daily',
      instructions: this.newMedication.notes || null,
      notes: this.newMedication.notes || null,
      start_date: this.newMedication.start_date || null,
      end_date: this.newMedication.end_date || null,
    };

    this.isSaving = true;
    this.http.post(this.apiUrl, payload, { headers: this.getHeaders() })
    .pipe(finalize(() => this.isSaving = false))
    .subscribe({
      next: (res: any) => {
        this.medications.unshift(this.mapMedication(res?.data || res));
        this.successMessage = res?.message || 'Medication created successfully';
        this.resetForm();
      },
      error: (err) => this.errorMessage = this.apiErrorMessage(err, 'Save failed')
    });
  }

  addMedicalRecord(): void {
    if (!this.newRecord.title || !this.newRecord.file) {
      this.errorMessage = 'Please provide a title and select a file (PDF/Image)';
      return;
    }
    
    // محاكاة الإضافة (يجب ربطها بـ FormData API لرفع الملفات حقيقةً)
    this.medicalRecords.push({ ...this.newRecord, id: Date.now() });
    this.successMessage = 'Medical record added successfully';
    this.newRecord = { title: '', date: '' };
    (document.getElementById('fileInput') as HTMLInputElement).value = '';
  }

  deleteMedication(id?: number, index?: number): void {
    if (!id) return;
    this.http.delete(`${this.apiUrl}/${id}`, { headers: this.getHeaders() })
    .subscribe({
      next: () => this.medications.splice(index!, 1),
      error: () => this.errorMessage = 'Delete failed'
    });
  }

  resetForm(): void {
    this.newMedication = { drug_name: '', dosage: '', schedule_time: '', frequency: 'daily', notes: '' };
  }

  private mapMedication(med: any): Medication {
    const name = med?.drug_name || med?.name || med?.medicine_name || med?.med_name || '';
    const time = med?.schedule_time || med?.reminder_time || '';

    return {
      ...med,
      name,
      drug_name: name,
      medicine_name: name,
      schedule_time: time ? String(time).slice(0, 5) : '',
      reminder_time: time ? String(time).slice(0, 5) : '',
      notes: med?.notes || med?.instructions || '',
    };
  }

  private apiErrorMessage(err: any, fallback: string): string {
    const errors = err?.error?.errors;
    if (errors) {
      return Object.values(errors).flat().join(' ');
    }

    if (err?.status === 0) {
      return 'Cannot connect to Riva API. Please start the Laravel backend on http://https://riva-healthcare-tm.gamer.gd and try again.';
    }
    if (err?.status === 401) return 'Please log in again before saving medication.';
    if (err?.status === 403) return 'Only patient accounts can save medications.';

    return err?.error?.message || fallback;
  }
}
