import { ComponentFixture, TestBed } from '@angular/core/testing';
import { DashboardFollowRequestsComponent } from './dashboard-follow-requests.component';
import { FollowRequestView } from '../dashboard-view-models';

const REQUEST: FollowRequestView = {
  id: 1, name: 'Alice Ahmed', avatar: 'https://example.com/a.png', condition: 'Hypertension',
  requestedAt: '25 Aug', isProcessing: false,
};

describe('DashboardFollowRequestsComponent', () => {
  let fixture: ComponentFixture<DashboardFollowRequestsComponent>;
  let component: DashboardFollowRequestsComponent;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [DashboardFollowRequestsComponent] });
    fixture = TestBed.createComponent(DashboardFollowRequestsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('creates', () => {
    expect(component).toBeTruthy();
  });

  it('emits accept with the exact request row', () => {
    const spy = jasmine.createSpy();
    component.accept.subscribe(spy);
    component.accept.emit(REQUEST);
    expect(spy).toHaveBeenCalledWith(REQUEST);
  });

  it('emits reject with the exact request row', () => {
    const spy = jasmine.createSpy();
    component.reject.subscribe(spy);
    component.reject.emit(REQUEST);
    expect(spy).toHaveBeenCalledWith(REQUEST);
  });
});
