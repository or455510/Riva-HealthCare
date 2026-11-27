import { CommonModule, DatePipe } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { AdminService } from './admin.service';

interface ColumnConfig {
  label: string;
  key: string;
  type?: 'text' | 'date' | 'badge' | 'currency';
}

interface FilterConfig {
  key: string;
  label: string;
  options: { label: string; value: string }[];
}

interface PageConfig {
  title: string;
  subtitle: string;
  endpoint: string;
  searchPlaceholder: string;
  columns: ColumnConfig[];
  filters?: FilterConfig[];
  actions?: string[];
  settings?: boolean;
}

interface DetailField {
  label: string;
  key: string;
  type?: 'date' | 'badge' | 'list';
  long?: boolean;
}

const PAGE_CONFIGS: Record<string, PageConfig> = {
  users: {
    title: 'Users Management',
    subtitle: 'Search, review, edit, activate, and suspend platform users.',
    endpoint: 'users-management',
    searchPlaceholder: 'Search name, email, or phone',
    filters: [
      { key: 'role', label: 'Role', options: [{ label: 'All roles', value: '' }, { label: 'Patients', value: 'patient' }, { label: 'Doctors', value: 'doctor' }, { label: 'Caregivers', value: 'caregiver' }, { label: 'Admins', value: 'admin' }] },
      { key: 'status', label: 'Status', options: [{ label: 'Any status', value: '' }, { label: 'Active', value: 'active' }, { label: 'Inactive', value: 'inactive' }, { label: 'Suspended', value: 'suspended' }] },
    ],
    columns: [
      { label: 'Name', key: 'name' },
      { label: 'Email', key: 'email' },
      { label: 'Role', key: 'role', type: 'badge' },
      { label: 'Status', key: 'status', type: 'badge' },
      { label: 'Last Login', key: 'last_login_at', type: 'date' },
    ],
    actions: ['view', 'edit', 'toggleUser'],
  },
  'doctor-verification': {
    title: 'Doctor Verification',
    subtitle: 'Approve or reject doctors after reviewing their submitted profile data.',
    endpoint: 'doctor-verifications',
    searchPlaceholder: 'Search doctor, specialty, or license',
    filters: [{ key: 'status', label: 'Status', options: [{ label: 'Pending', value: 'pending' }, { label: 'Approved', value: 'approved' }, { label: 'Rejected', value: 'rejected' }, { label: 'All', value: '' }] }],
    columns: [
      { label: 'Doctor', key: 'doctor.user.name' },
      { label: 'Email', key: 'doctor.user.email' },
      { label: 'Specialization', key: 'specialization' },
      { label: 'License', key: 'medical_license_number' },
      { label: 'Status', key: 'status', type: 'badge' },
      { label: 'Submitted', key: 'created_at', type: 'date' },
    ],
    actions: ['view', 'approveDoctor', 'rejectDoctor'],
  },
  'patient-monitoring': {
    title: 'Patient Monitoring',
    subtitle: 'Track risk status, care relationships, last login, and missed medication signals.',
    endpoint: 'patient-monitoring',
    searchPlaceholder: 'Search patient',
    columns: [
      { label: 'Patient', key: 'user.name' },
      { label: 'Email', key: 'user.email' },
      { label: 'Risk', key: 'risk_status', type: 'badge' },
      { label: 'Missed Doses', key: 'missed_medication_count' },
      { label: 'Last Report', key: 'last_report_at', type: 'date' },
    ],
    actions: ['view'],
  },
  emergencies: {
    title: 'Emergency Center',
    subtitle: 'Audit urgent alerts, delivery status, and resolution state.',
    endpoint: 'emergencies',
    searchPlaceholder: 'Search patient',
    filters: [
      { key: 'severity', label: 'Severity', options: [{ label: 'Any severity', value: '' }, { label: 'Medium', value: 'medium' }, { label: 'High', value: 'high' }, { label: 'Critical', value: 'critical' }] },
      { key: 'status', label: 'Status', options: [{ label: 'Any status', value: '' }, { label: 'Active', value: 'active' }, { label: 'Resolved', value: 'resolved' }] },
    ],
    columns: [
      { label: 'Patient', key: 'patient.user.name' },
      { label: 'Severity', key: 'severity', type: 'badge' },
      { label: 'Pain', key: 'pain_level' },
      { label: 'Email Sent', key: 'admin_email_sent_at', type: 'date' },
      { label: 'Status', key: 'status', type: 'badge' },
    ],
    actions: ['view', 'resolveEmergency'],
  },
  'daily-reports': {
    title: 'Daily Reports',
    subtitle: 'Review patient check-ins, pain levels, symptoms, and risk classification.',
    endpoint: 'daily-reports',
    searchPlaceholder: 'Search patient',
    filters: [{ key: 'risk', label: 'Risk', options: [{ label: 'Any risk', value: '' }, { label: 'Stable', value: 'stable' }, { label: 'Attention needed', value: 'attention_needed' }, { label: 'Moderate risk', value: 'moderate_risk' }, { label: 'High risk', value: 'high_risk' }] }],
    columns: [
      { label: 'Patient', key: 'patient.user.name' },
      { label: 'Pain', key: 'pain_level' },
      { label: 'Symptoms', key: 'symptoms' },
      { label: 'Risk', key: 'risk_level', type: 'badge' },
      { label: 'Submitted', key: 'created_at', type: 'date' },
    ],
    actions: ['view'],
  },
  appointments: {
    title: 'Appointments & Payments',
    subtitle: 'Monitor appointment lifecycle, payment status, and care demand.',
    endpoint: 'appointments-payments',
    searchPlaceholder: 'Search patient or doctor',
    filters: [
      { key: 'status', label: 'Appointment', options: [{ label: 'Any status', value: '' }, { label: 'Pending payment', value: 'pending_payment' }, { label: 'Pending', value: 'pending' }, { label: 'Confirmed', value: 'confirmed' }, { label: 'Completed', value: 'completed' }, { label: 'Cancelled', value: 'cancelled' }] },
      { key: 'payment_status', label: 'Payment', options: [{ label: 'Any payment', value: '' }, { label: 'Unpaid', value: 'unpaid' }, { label: 'Paid', value: 'paid' }, { label: 'Failed', value: 'failed' }] },
    ],
    columns: [
      { label: 'Patient', key: 'patient.user.name' },
      { label: 'Doctor', key: 'doctor.user.name' },
      { label: 'Date', key: 'appointment_date' },
      { label: 'Status', key: 'status', type: 'badge' },
      { label: 'Payment', key: 'payment_status', type: 'badge' },
      { label: 'Amount', key: 'amount', type: 'currency' },
    ],
    actions: ['view'],
  },
  'contact-messages': {
    title: 'Contact Messages',
    subtitle: 'Track incoming visitor messages and admin notification delivery.',
    endpoint: 'contact-messages',
    searchPlaceholder: 'Search name, email, or subject',
    filters: [{ key: 'status', label: 'Status', options: [{ label: 'Any status', value: '' }, { label: 'New', value: 'new' }, { label: 'Open', value: 'open' }, { label: 'Handled', value: 'handled' }] }],
    columns: [
      { label: 'Sender', key: 'full_name' },
      { label: 'Email', key: 'email' },
      { label: 'Subject', key: 'subject' },
      { label: 'Email Sent', key: 'admin_email_sent_at', type: 'date' },
      { label: 'WhatsApp', key: 'whatsapp_sent_at', type: 'date' },
      { label: 'Status', key: 'status', type: 'badge' },
    ],
    actions: ['view', 'handleContact'],
  },
  notifications: {
    title: 'Notifications Center',
    subtitle: 'Inspect notification delivery, read state, type, and recipient.',
    endpoint: 'notifications-center',
    searchPlaceholder: 'Search not available',
    filters: [
      { key: 'status', label: 'Read state', options: [{ label: 'Any', value: '' }, { label: 'Unread', value: 'unread' }, { label: 'Read', value: 'read' }] },
      { key: 'category', label: 'Category', options: [{ label: 'Any category', value: '' }, { label: 'Emergency', value: 'emergency' }, { label: 'Reports', value: 'reports' }, { label: 'Appointment', value: 'appointment' }, { label: 'Medication', value: 'medication' }] },
    ],
    columns: [
      { label: 'Title', key: 'title' },
      { label: 'Type', key: 'type', type: 'badge' },
      { label: 'User ID', key: 'notifiable_id' },
      { label: 'Email', key: 'email_status', type: 'badge' },
      { label: 'WhatsApp', key: 'whatsapp_status', type: 'badge' },
      { label: 'Created', key: 'created_at', type: 'date' },
    ],
    actions: ['view', 'resendNotification'],
  },
  'activity-logs': {
    title: 'Activity Logs',
    subtitle: 'Search user and system activity by action, role, and date.',
    endpoint: 'activity',
    searchPlaceholder: 'Search action or description',
    filters: [
      { key: 'role', label: 'Role', options: [{ label: 'Any role', value: '' }, { label: 'Patient', value: 'patient' }, { label: 'Doctor', value: 'doctor' }, { label: 'Caregiver', value: 'caregiver' }, { label: 'Admin', value: 'admin' }] },
    ],
    columns: [
      { label: 'Action', key: 'action', type: 'badge' },
      { label: 'Description', key: 'description' },
      { label: 'User', key: 'user.name' },
      { label: 'Role', key: 'user.role', type: 'badge' },
      { label: 'Date', key: 'created_at', type: 'date' },
    ],
    actions: ['view'],
  },
  settings: {
    title: 'System Settings',
    subtitle: 'Review public platform configuration status without exposing secrets.',
    endpoint: 'settings',
    searchPlaceholder: '',
    settings: true,
    columns: [],
  },
  profile: {
    title: 'Admin Profile',
    subtitle: 'Profile-level admin information and platform access context.',
    endpoint: 'settings',
    searchPlaceholder: '',
    settings: true,
    columns: [],
  },
};

@Component({
  selector: 'app-admin-data-page',
  standalone: true,
  imports: [CommonModule, FormsModule, DatePipe],
  template: `
    <section class="admin-page" [class.emergency-page]="pageKey === 'emergencies'">
      <div class="page-title">
        <div>
          <span class="page-kicker">Admin Operations</span>
          <h2>{{ config.title }}</h2>
          <p>{{ config.subtitle }}</p>
        </div>
        <button class="ghost" (click)="load()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
      </div>

      <div *ngIf="message" class="toast success">{{ message }}</div>
      <div *ngIf="error" class="toast error">{{ error }}</div>

      <ng-container *ngIf="config.settings; else tablePage">
        <div class="settings-grid" *ngIf="!loading">
          <article *ngFor="let item of settingsItems" class="setting-card">
            <p>{{ item.label }}</p>
            <strong [class.ok]="item.ok === true" [class.warn]="item.ok === false">{{ item.value }}</strong>
          </article>
        </div>
      </ng-container>

      <ng-template #tablePage>
        <div class="toolbar">
          <label class="search-field" *ngIf="config.searchPlaceholder !== 'Search not available'">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input [(ngModel)]="params['search']" (keyup.enter)="search()" [placeholder]="config.searchPlaceholder" />
          </label>
          <select *ngFor="let filter of config.filters" [(ngModel)]="params[filter.key]" (change)="search()">
            <option *ngFor="let option of filter.options" [value]="option.value">{{ option.label }}</option>
          </select>
          <input *ngIf="pageKey === 'activity-logs'" type="date" [(ngModel)]="params['date']" (change)="search()" />
          <button class="primary-filter" (click)="search()">Search</button>
        </div>

        <div class="table-card">
          <div *ngIf="loading" class="loading-list">
            <div *ngFor="let row of skeletonRows" class="skeleton"></div>
          </div>

          <div *ngIf="!loading && rows.length === 0" class="empty">
            <i class="fa-solid fa-folder-open"></i>
            <strong>No records found</strong>
            <p>Try changing search or filters.</p>
          </div>

          <table *ngIf="!loading && rows.length > 0">
            <thead>
              <tr>
                <th *ngFor="let column of config.columns">{{ column.label }}</th>
                <th *ngIf="config.actions?.length">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let row of rows" [class.critical-alert]="isCriticalEmergency(row)">
                <td *ngFor="let column of config.columns">
                  <span *ngIf="column.type === 'badge'" class="badge" [ngClass]="badgeClass(value(row, column.key))">{{ value(row, column.key) || 'unknown' }}</span>
                  <span *ngIf="column.type === 'date'">{{ value(row, column.key) ? (value(row, column.key) | date:'medium') : 'Not sent' }}</span>
                  <span *ngIf="column.type === 'currency'">{{ value(row, column.key) | currency }}</span>
                  <span *ngIf="!column.type || column.type === 'text'">{{ short(value(row, column.key)) }}</span>
                </td>
                <td *ngIf="config.actions?.length" class="actions">
                  <button class="table-action" *ngIf="hasAction('view')" (click)="open(row)">View</button>
                  <button class="table-action" *ngIf="hasAction('edit')" (click)="openEdit(row)">Edit</button>
                  <button class="table-action danger-action" *ngIf="hasAction('toggleUser')" (click)="toggleUser(row)">{{ row.is_active ? 'Deactivate' : 'Activate' }}</button>
                  <button class="table-action success-action" *ngIf="hasAction('approveDoctor') && row.status !== 'approved'" (click)="approveDoctor(row)">Approve</button>
                  <button class="table-action danger-action" *ngIf="hasAction('rejectDoctor') && row.status !== 'rejected'" (click)="rejectDoctor(row)">Reject</button>
                  <button class="table-action danger-action" *ngIf="hasAction('resolveEmergency') && row.status !== 'resolved'" (click)="resolveEmergency(row)">Resolve</button>
                  <button class="table-action success-action" *ngIf="hasAction('handleContact') && row.status !== 'handled'" (click)="handleContact(row)">Handled</button>
                  <button class="table-action" *ngIf="hasAction('resendNotification')" (click)="resendNotification(row)">Resend</button>
                </td>
              </tr>
            </tbody>
          </table>

          <div *ngIf="pagination" class="pagination">
            <span>Page {{ pagination.current_page }} of {{ pagination.last_page }} · {{ pagination.total }} records</span>
            <div>
              <button class="pager" [disabled]="pagination.current_page <= 1" (click)="goTo(pagination.current_page - 1)">Previous</button>
              <button class="pager" [disabled]="pagination.current_page >= pagination.last_page" (click)="goTo(pagination.current_page + 1)">Next</button>
            </div>
          </div>
        </div>
      </ng-template>

      <div class="modal-backdrop" *ngIf="selected" (click)="close()">
        <article class="modal" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h3>{{ editMode ? 'Edit User' : 'Record Details' }}</h3>
            <button (click)="close()">×</button>
          </div>

          <form *ngIf="editMode" (ngSubmit)="saveUser()">
            <label>First Name<input [(ngModel)]="editForm.first_name" name="first_name"></label>
            <label>Last Name<input [(ngModel)]="editForm.last_name" name="last_name"></label>
            <label>Email<input [(ngModel)]="editForm.email" name="email"></label>
            <label>Phone<input [(ngModel)]="editForm.phone" name="phone"></label>
            <label>Address<input [(ngModel)]="editForm.address" name="address"></label>
            <button class="primary" type="submit">Save changes</button>
          </form>

          <div *ngIf="!editMode" class="detail-body">
            <div class="detail-grid">
              <div *ngFor="let field of detailFields" class="detail-item">
                <span>{{ field.label }}</span>
                <strong [class.wrap]="field.long">{{ formatDetailValue(field) }}</strong>
              </div>
            </div>

            <div *ngIf="detailMessage" class="message-card">
              <span>{{ detailMessage.label }}</span>
              <p>{{ detailMessage.value }}</p>
            </div>

            <div *ngIf="detailDocuments.length" class="document-grid">
              <a *ngFor="let doc of detailDocuments" [href]="doc.url" target="_blank" rel="noopener">
                <i class="fa-solid fa-file-shield"></i>
                <span>{{ doc.label }}</span>
                <small>Preview / download</small>
              </a>
            </div>
          </div>
        </article>
      </div>
    </section>
  `,
  styles: [`
    :host {
      --admin-bg: #F6F9FC;
      --admin-surface: #FFFFFF;
      --admin-navy: #0F172A;
      --admin-primary: #2563EB;
      --admin-primary-soft: #DBEAFE;
      --admin-cyan: #06B6D4;
      --admin-success: #16A34A;
      --admin-warning: #F59E0B;
      --admin-danger: #DC2626;
      --admin-border: #E5E7EB;
      --admin-muted: #64748B;
    }
    .admin-page { display: grid; gap: 16px; animation: pageIn 160ms ease both; }
    .page-title, .toolbar, .table-card, .setting-card {
      background: #ffffff;
      border: 1px solid var(--admin-border);
      border-radius: 18px;
      box-shadow: 0 14px 34px rgba(15, 23, 42, .05);
    }
    .page-title {
      position: relative;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      min-height: 118px;
      padding: 20px;
      overflow: hidden;
    }
    .page-title::after {
      content: "";
      position: absolute;
      right: -48px;
      top: -70px;
      width: 210px;
      height: 210px;
      border-radius: 999px;
      background: rgba(56, 189, 248, .13);
    }
    .page-kicker {
      display: inline-flex;
      margin-bottom: 8px;
      color: var(--admin-primary);
      font-size: 11px;
      font-weight: 950;
      letter-spacing: .08em;
      text-transform: uppercase;
    }
    h2 { margin: 0; color: var(--admin-navy); font-size: clamp(24px, 2.2vw, 30px); font-weight: 850; letter-spacing: 0; }
    p { margin: 7px 0 0; max-width: 760px; color: var(--admin-muted); font-size: 14px; font-weight: 600; line-height: 1.55; }
    .toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      padding: 14px;
      align-items: center;
    }
    .search-field {
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1;
      min-width: min(340px, 100%);
      min-height: 46px;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 0 14px;
      background: #f8fbff;
      color: #64748b;
    }
    input, select, form label input {
      min-height: 46px;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 0 14px;
      background: #ffffff;
      outline: none;
      font-weight: 700;
      color: #0f172a;
    }
    .search-field input {
      width: 100%;
      min-width: 0;
      border: 0;
      padding: 0;
      background: transparent;
    }
    button {
      min-height: 40px;
      border: 0;
      border-radius: 13px;
      padding: 0 14px;
      background: #eff6ff;
      color: #2563eb;
      font-weight: 900;
      cursor: pointer;
      transition: transform .2s ease, background .2s ease, box-shadow .2s ease;
    }
    button:hover { transform: translateY(-1px) scale(1.02); background: #dbeafe; }
    button:disabled { opacity: .45; cursor: not-allowed; }
    .ghost, .primary-filter { position: relative; z-index: 1; display: inline-flex; align-items: center; gap: 8px; background: #0f172a; color: white; box-shadow: 0 14px 28px rgba(15, 23, 42, .16); }
    .primary-filter { background: #2563eb; box-shadow: 0 14px 28px rgba(37, 99, 235, .18); }
    .primary { background: #2563eb; color: white; width: 100%; }
    .table-card { overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 13px 16px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: middle; }
    th { color: #64748b; background: #f8fafc; font-size: 11px; font-weight: 950; text-transform: uppercase; letter-spacing: .08em; }
    td { color: #0f172a; font-size: 13px; font-weight: 650; }
    tbody tr {
      transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    tbody tr:hover {
      background: #f8fbff;
      box-shadow: inset 3px 0 0 #2563eb;
    }
    tbody tr.critical-alert {
      background: linear-gradient(90deg, rgba(239, 68, 68, .08), transparent 58%);
      box-shadow: inset 3px 0 0 #ef4444;
    }
    tbody tr.critical-alert td:first-child::before {
      content: "";
      display: inline-block;
      width: 9px;
      height: 9px;
      margin-right: 8px;
      border-radius: 999px;
      background: #ef4444;
      animation: emergencyPulse 1.5s infinite;
    }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .table-action {
      min-height: 34px;
      border-radius: 12px;
      background: #eff6ff;
      color: #2563eb;
      font-size: 12px;
      box-shadow: none;
    }
    .success-action { background: rgba(34, 197, 94, .12); color: #15803d; }
    .danger-action { background: rgba(239, 68, 68, .11); color: #dc2626; }
    .badge { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 7px 11px; font-size: 12px; font-weight: 950; text-transform: capitalize; background: #e2e8f0; color: #475569; }
    .badge::before { content: ""; width: 7px; height: 7px; border-radius: 999px; background: currentColor; opacity: .72; }
    .badge.ok { background: rgba(34, 197, 94, .12); color: #15803d; }
    .badge.warn { background: rgba(245, 158, 11, .14); color: #b45309; }
    .badge.danger { background: rgba(239, 68, 68, .12); color: #b91c1c; }
    .badge.info { background: rgba(37, 99, 235, .11); color: #1d4ed8; }
    .pagination { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; color: #64748b; font-weight: 800; }
    .pagination div { display: flex; gap: 8px; }
    .pager { background: #ffffff; border: 1px solid #e2e8f0; color: #0f172a; }
    .empty { text-align: center; padding: 54px 20px; color: #64748b; }
    .empty i { display: block; margin-bottom: 14px; font-size: 34px; color: #93c5fd; }
    .loading-list { padding: 16px; display: grid; gap: 10px; }
    .skeleton { height: 58px; border-radius: 16px; background: linear-gradient(90deg, #fff, #eff6ff, #fff); background-size: 200% 100%; animation: shimmer 1.2s infinite; }
    .toast { padding: 14px 16px; border-radius: 16px; font-weight: 850; }
    .toast.success { background: #dcfce7; color: #15803d; }
    .toast.error { background: #fee2e2; color: #991b1b; }
    .settings-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .setting-card { padding: 20px; transition: transform .22s ease, box-shadow .22s ease; }
    .setting-card:hover { transform: translateY(-4px); box-shadow: 0 22px 48px rgba(15, 23, 42, .09); }
    .setting-card p { text-transform: uppercase; font-size: 12px; letter-spacing: .06em; }
    .setting-card strong { display: block; margin-top: 10px; font-size: 22px; overflow-wrap: anywhere; }
    .setting-card strong.ok { color: #16a34a; }.setting-card strong.warn { color: #dc2626; }
    .modal-backdrop { position: fixed; inset: 0; display: grid; place-items: center; background: rgba(15,23,42,.54); backdrop-filter: blur(10px); z-index: 80; padding: 20px; animation: fade .2s ease both; }
    .modal { width: min(820px, 100%); max-height: 82vh; overflow: auto; background: white; border-radius: 22px; border: 1px solid #e2e8f0; box-shadow: 0 30px 80px rgba(15,23,42,.28); animation: modalIn 180ms ease both; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; border-bottom: 1px solid #eff6ff; }
    .modal-header h3 { margin: 0; font-weight: 950; }
    .detail-body { padding: 20px; display: grid; gap: 16px; }
    .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .detail-item, .message-card, .document-grid a {
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      background: #f8fbff;
      padding: 14px;
    }
    .detail-item span, .message-card span {
      display: block;
      color: #64748b;
      font-size: 11px;
      font-weight: 850;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .detail-item strong {
      display: block;
      margin-top: 6px;
      color: #0f172a;
      font-size: 14px;
      font-weight: 800;
    }
    .detail-item strong.wrap { white-space: pre-wrap; overflow-wrap: anywhere; }
    .message-card p {
      margin: 8px 0 0;
      color: #0f172a;
      white-space: pre-wrap;
      font-weight: 650;
    }
    .document-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .document-grid a { display: flex; align-items: center; gap: 12px; color: #2563eb; text-decoration: none; transition: transform 160ms ease, box-shadow 160ms ease; }
    .document-grid a:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(37,99,235,.12); }
    .document-grid i { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 14px; background: #dbeafe; }
    .document-grid span, .document-grid small { display: block; }
    .document-grid span { color: #0f172a; font-weight: 850; }
    .document-grid small { color: #64748b; font-size: 12px; font-weight: 700; }
    form { display: grid; gap: 14px; padding: 20px; }
    form label { display: grid; gap: 6px; font-weight: 850; color: #475569; }
    @keyframes pageIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes modalIn { from { opacity: 0; transform: translateY(18px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @keyframes shimmer { to { background-position: -200% 0; } }
    @keyframes emergencyPulse {
      0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, .42); }
      70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); }
      100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    @media (max-width: 920px) { .settings-grid, .detail-grid, .document-grid { grid-template-columns: 1fr; } .page-title, .pagination { flex-direction: column; align-items: flex-start; } table { min-width: 900px; } .table-card { overflow-x: auto; } }
  `]
})
export class AdminDataPageComponent implements OnInit {
  pageKey = 'users';
  config: PageConfig = PAGE_CONFIGS['users'];
  rows: any[] = [];
  settings: any;
  pagination: any;
  params: Record<string, any> = { page: 1, per_page: 10 };
  loading = true;
  error = '';
  message = '';
  selected: any = null;
  editMode = false;
  editForm: any = {};
  skeletonRows = Array.from({ length: 6 });

  constructor(private route: ActivatedRoute, private admin: AdminService) {}

  ngOnInit(): void {
    this.route.data.subscribe(data => {
      this.pageKey = data['page'] || 'users';
      this.config = PAGE_CONFIGS[this.pageKey];
      this.params = { page: 1, per_page: 10 };
      for (const filter of this.config.filters || []) {
        this.params[filter.key] = filter.options[0]?.value || '';
      }
      this.load();
    });
  }

  load(): void {
    this.loading = true;
    this.error = '';
    this.admin.get(this.config.endpoint, this.params).subscribe({
      next: (res) => {
        if (this.config.settings) {
          this.settings = res.data;
        } else {
          this.rows = res.data?.data || [];
          this.pagination = res.data;
        }
        this.loading = false;
      },
      error: (err) => {
        this.error = err?.error?.message || 'Unable to load admin data.';
        this.loading = false;
      },
    });
  }

  search(): void {
    this.params['page'] = 1;
    this.load();
  }

  goTo(page: number): void {
    this.params['page'] = page;
    this.load();
  }

  value(row: any, key: string): any {
    return key.split('.').reduce((value, part) => value?.[part], row);
  }

  short(value: any): string {
    if (value === null || value === undefined || value === '') return '—';
    const text = String(value);
    return text.length > 90 ? `${text.slice(0, 90)}...` : text;
  }

  badgeClass(value: any): string {
    const text = String(value || '').toLowerCase();
    if (['active', 'verified', 'approved', 'paid', 'sent', 'stable', 'completed', 'read', 'handled'].includes(text)) return 'ok';
    if (['pending', 'pending_payment', 'moderate_risk', 'attention_needed', 'not_sent'].includes(text)) return 'warn';
    if (['critical', 'high', 'high_risk', 'failed', 'rejected', 'inactive', 'suspended', 'unread'].includes(text)) return 'danger';
    return 'info';
  }

  isCriticalEmergency(row: any): boolean {
    return this.pageKey === 'emergencies'
      && String(row?.status || 'active').toLowerCase() !== 'resolved'
      && ['critical', 'high'].includes(String(row?.severity || '').toLowerCase());
  }

  hasAction(action: string): boolean {
    return !!this.config.actions?.includes(action);
  }

  open(row: any): void {
    this.selected = row;
    this.editMode = false;
  }

  openEdit(row: any): void {
    this.selected = row;
    this.editMode = true;
    this.editForm = { ...row };
  }

  close(): void {
    this.selected = null;
    this.editMode = false;
  }

  saveUser(): void {
    this.admin.put(`users/${this.selected.id}`, this.editForm).subscribe({
      next: (res) => { this.message = res.message; this.close(); this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to update user.',
    });
  }

  toggleUser(row: any): void {
    this.admin.patch(`users/${row.id}/admin-status`, { is_active: !row.is_active }).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to update user status.',
    });
  }

  approveDoctor(row: any): void {
    this.admin.post(`doctor-verifications/${row.id}/approve`).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to approve doctor.',
    });
  }

  rejectDoctor(row: any): void {
    const reason = prompt('Enter rejection reason');
    if (!reason) return;
    this.admin.post(`doctor-verifications/${row.id}/reject`, { reason }).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to reject doctor.',
    });
  }

  get detailFields(): DetailField[] {
    switch (this.pageKey) {
      case 'users':
        return [
          this.field('Name', 'name'),
          this.field('Email', 'email'),
          this.field('Phone', 'phone'),
          this.field('Address', 'address', undefined, true),
          this.field('Role', 'role'),
          this.field('Status', 'status'),
          this.field('Last Login', 'last_login_at', 'date'),
        ];
      case 'doctor-verification':
        return [
          this.field('Doctor', 'doctor.user.name'),
          this.field('Email', 'doctor.user.email'),
          this.field('Specialization', 'specialization'),
          this.field('Clinic / Hospital', 'clinic_or_hospital_name'),
          this.field('Medical License Number', 'medical_license_number'),
          this.field('Syndicate Number', 'syndicate_number'),
          this.field('Years of Experience', 'years_of_experience'),
          this.field('Status', 'status'),
          this.field('Submitted', 'created_at', 'date'),
          this.field('Reviewed', 'reviewed_at', 'date'),
        ];
      case 'patient-monitoring':
        return [
          this.field('Patient', 'user.name'),
          this.field('Email', 'user.email'),
          this.field('Phone', 'user.phone'),
          this.field('Address', 'user.address', undefined, true),
          this.field('Caregivers', 'caregivers', 'list', true),
          this.field('Doctors', 'doctors', 'list', true),
          this.field('Last Login', 'last_login_at', 'date'),
          this.field('Missed Medication Count', 'missed_medication_count'),
          this.field('Risk Status', 'risk_status'),
          this.field('Last Report', 'last_report_at', 'date'),
        ];
      case 'daily-reports':
        return [
          this.field('Patient', 'patient.user.name'),
          this.field('Pain Level', 'pain_level'),
          this.field('Risk Level', 'risk_level'),
          this.field('Medication Taken', 'medication_taken'),
          this.field('Created Date', 'created_at', 'date'),
          this.field('Symptoms', 'symptoms', undefined, true),
          this.field('Notes', 'notes', undefined, true),
        ];
      case 'notifications':
        return [
          this.field('Title', 'title'),
          this.field('Type', 'type'),
          this.field('Category', 'category'),
          this.field('User', 'notifiable.name'),
          this.field('Email Status', 'email_status'),
          this.field('WhatsApp Status', 'whatsapp_status'),
          this.field('Created Date', 'created_at', 'date'),
        ];
      case 'contact-messages':
        return [
          this.field('Sender', 'full_name'),
          this.field('Email', 'email'),
          this.field('Subject', 'subject'),
          this.field('Email Sent', 'admin_email_sent_at', 'date'),
          this.field('WhatsApp Sent', 'whatsapp_sent_at', 'date'),
          this.field('Handled At', 'handled_at', 'date'),
          this.field('Status', 'status'),
        ];
      case 'emergencies':
        return [
          this.field('Patient', 'patient.user.name'),
          this.field('Severity', 'severity'),
          this.field('Status', 'status'),
          this.field('Pain Level', 'pain_level'),
          this.field('Email Sent', 'admin_email_sent_at', 'date'),
          this.field('WhatsApp Sent', 'whatsapp_sent_at', 'date'),
          this.field('Created Date', 'created_at', 'date'),
        ];
      default:
        return this.config.columns.map(column => this.field(column.label, column.key, column.type === 'date' ? 'date' : undefined));
    }
  }

  get detailMessage(): { label: string; value: string } | null {
    if (!this.selected) return null;
    const candidates: Record<string, string[]> = {
      notifications: ['message'],
      'contact-messages': ['message'],
      emergencies: ['message', 'symptoms'],
      'daily-reports': ['symptoms', 'notes'],
      'doctor-verification': ['rejection_reason'],
    };
    const key = (candidates[this.pageKey] || []).find(field => this.value(this.selected, field));
    return key ? { label: this.labelize(key), value: String(this.value(this.selected, key)) } : null;
  }

  get detailDocuments(): { label: string; url: string }[] {
    if (!this.selected) return [];
    const docs = [
      { label: 'National ID', key: 'national_id_file_url' },
      { label: 'Medical License', key: 'medical_license_file_url' },
      { label: 'Syndicate Card', key: 'syndicate_card_file_url' },
      { label: 'Certificate / Proof', key: 'certificate_file_url' },
    ];
    return docs
      .map(doc => ({ label: doc.label, url: this.value(this.selected, doc.key) }))
      .filter(doc => !!doc.url);
  }

  formatDetailValue(field: DetailField): string {
    const raw = this.value(this.selected, field.key);
    if (raw === null || raw === undefined || raw === '') return 'Not provided';
    if (field.type === 'date') return new Date(raw).toLocaleString();
    if (field.type === 'list') return this.formatList(raw);
    if (typeof raw === 'boolean') return raw ? 'Yes' : 'No';
    return String(raw);
  }

  private field(label: string, key: string, type?: DetailField['type'], long = false): DetailField {
    return { label, key, type, long };
  }

  private formatList(value: any): string {
    if (!Array.isArray(value) || value.length === 0) return 'None assigned';
    return value
      .map(item => item?.caregiver?.name || item?.doctor?.name || item?.user?.name || item?.name || item?.status || 'Record')
      .join(', ');
  }

  private labelize(key: string): string {
    return key.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
  }

  resolveEmergency(row: any): void {
    this.admin.post(`emergencies/${row.id}/resolve`).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to resolve emergency.',
    });
  }

  handleContact(row: any): void {
    this.admin.post(`contact-messages/${row.id}/handled`).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to mark message handled.',
    });
  }

  resendNotification(row: any): void {
    this.admin.post(`notifications-center/${row.id}/resend`).subscribe({
      next: (res) => { this.message = res.message; this.load(); },
      error: (err) => this.error = err?.error?.message || 'Unable to resend notification.',
    });
  }

  get settingsItems() {
    const s = this.settings || {};
    return [
      { label: 'Admin Email', value: s.admin_email || 'Not configured' },
      { label: 'Admin WhatsApp', value: s.admin_whatsapp_number || 'Not configured' },
      { label: 'Mail Config', value: s.mail_configured ? 'Configured' : 'Missing', ok: !!s.mail_configured },
      { label: 'WhatsApp Config', value: s.whatsapp_configured ? 'Configured' : 'Missing', ok: !!s.whatsapp_configured },
      { label: 'Gemini Config', value: s.gemini_configured ? 'Configured' : 'Missing', ok: !!s.gemini_configured },
      { label: 'Missed Dose Grace', value: `${s.missed_dose_grace_minutes ?? 30} minutes` },
      { label: 'Platform Location', value: s.platform_contact?.location || 'Not configured' },
      { label: 'Platform Phone', value: s.platform_contact?.phone || 'Not configured' },
      { label: 'Platform Email', value: s.platform_contact?.email || 'Not configured' },
    ];
  }
}
