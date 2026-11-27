import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { DashboardQueueComponent } from './dashboard-queue.component';
import { QueueEntry } from '../../../../../../../service/queue.service';

const NEXT_PATIENT: QueueEntry = {
  id: 2, patient_id: 11, patient_name: 'Bob Said', doctor_id: 42, doctor_name: 'Dr. Me',
  appointment_date: '2026-08-26', appointment_time: '09:15:00', type: 'checkup', status: 'confirmed',
  payment_status: 'paid', queue_status: 'waiting', queue_position: 2,
  checked_in_at: '2026-08-26T09:05:00Z', called_at: null, queue_completed_at: null,
};

describe('DashboardQueueComponent', () => {
  let fixture: ComponentFixture<DashboardQueueComponent>;
  let component: DashboardQueueComponent;
  let router: Router;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [DashboardQueueComponent],
      providers: [provideRouter([])],
    });
    fixture = TestBed.createComponent(DashboardQueueComponent);
    component = fixture.componentInstance;
    router = TestBed.inject(Router);
    component.queueView = { currentPatient: null, nextPatient: NEXT_PATIENT, waitingCount: 1, noShowTodayCount: 0, skippedTodayCount: 0 };
    fixture.detectChanges();
  });

  it('creates', () => {
    expect(component).toBeTruthy();
  });

  it('emits retry when the loadQueue retry button is used', () => {
    const spy = jasmine.createSpy();
    component.retry.subscribe(spy);
    component.retry.emit();
    expect(spy).toHaveBeenCalled();
  });

  it('emits callPatient with the exact queue entry when calling the next patient', () => {
    const spy = jasmine.createSpy();
    component.callPatient.subscribe(spy);
    component.callPatient.emit(NEXT_PATIENT);
    expect(spy).toHaveBeenCalledWith(NEXT_PATIENT);
  });

  it('navigates to /profile-p/:id when opening a patient', () => {
    const navigateSpy = spyOn(router, 'navigate');
    component.openPatient(11);
    expect(navigateSpy).toHaveBeenCalledWith(['/profile-p', 11]);
  });

  it('does nothing when opening a patient with no id', () => {
    const navigateSpy = spyOn(router, 'navigate');
    component.openPatient(null);
    expect(navigateSpy).not.toHaveBeenCalled();
  });
});
