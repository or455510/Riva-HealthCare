import {
  AfterViewChecked,
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  Output,
  ViewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { SidebarItem } from '../sidebar';

/**
 * Mobile App Shell (M1 — Mobile Redesign): a small, self-contained bottom
 * sheet for the mobile "More" menu.
 *
 * Note on tooling: Flowbite is present in this project only as a Tailwind
 * CSS plugin (`@plugin "flowbite/plugin"` in styles.css) — there is no
 * Flowbite JS runtime initialized anywhere (confirmed by reading every
 * *.service.ts under core/services; the one file named
 * core/services/flowbite/flowbite.service.ts is actually an unrelated
 * ProfileService, not a Flowbite initializer). navbar.component.html's own
 * `data-collapse-toggle` button is dead for the same reason. Rather than
 * wire up an untested library runtime for the first time (risk, and no
 * clear SSR story), this sheet is a few lines of plain Angular + CSS —
 * "create a small, clean component" per the explicit fallback instruction.
 */
@Component({
  selector: 'app-mobile-more-sheet',
  standalone: true,
  imports: [CommonModule, RouterLink],
  template: `
    <div *ngIf="open" class="mobile-more-sheet-backdrop md:hidden" (click)="close.emit()">
      <div
        class="mobile-more-sheet"
        role="dialog"
        aria-modal="true"
        aria-label="More"
        (click)="$event.stopPropagation()"
      >
        <div class="mobile-more-sheet__handle" aria-hidden="true"></div>
        <div class="mobile-more-sheet__header">
          <span>More</span>
          <button
            #closeBtn
            type="button"
            class="mobile-more-sheet__close"
            (click)="close.emit()"
            aria-label="Close"
          >
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>

        <nav class="mobile-more-sheet__list">
          <a
            *ngFor="let item of items"
            [routerLink]="item.route"
            class="mobile-more-sheet__item"
            (click)="close.emit()"
          >
            <i [class]="item.icon" aria-hidden="true"></i>
            <span>{{ item.title }}</span>
          </a>
        </nav>

        <button type="button" class="mobile-more-sheet__logout" (click)="logoutClick()">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
          <span>Logout</span>
        </button>
      </div>
    </div>
  `,
  styles: [`
    .mobile-more-sheet-backdrop {
      position: fixed;
      inset: 0;
      z-index: 50;
      background: rgba(15, 23, 42, 0.45);
      display: flex;
      align-items: flex-end;
    }
    .mobile-more-sheet {
      width: 100%;
      max-height: 75vh;
      overflow-y: auto;
      background: #ffffff;
      border-radius: 20px 20px 0 0;
      padding: 8px 8px calc(16px + env(safe-area-inset-bottom));
      box-shadow: 0 -8px 30px rgba(15, 23, 42, 0.15);
    }
    .mobile-more-sheet__handle {
      width: 40px;
      height: 4px;
      border-radius: 999px;
      background: #e2e8f0;
      margin: 6px auto 10px;
    }
    .mobile-more-sheet__header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 12px 8px;
      font-weight: 800;
      font-size: 15px;
      color: #1e293b;
    }
    .mobile-more-sheet__close {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      border-radius: 12px;
      color: #64748b;
      background: transparent;
      border: none;
    }
    .mobile-more-sheet__close:hover,
    .mobile-more-sheet__close:focus-visible {
      background: #f1f5f9;
      outline: none;
    }
    .mobile-more-sheet__list {
      display: flex;
      flex-direction: column;
      padding: 4px;
    }
    .mobile-more-sheet__item {
      display: flex;
      align-items: center;
      gap: 14px;
      min-height: 48px;
      padding: 0 12px;
      border-radius: 12px;
      color: #334155;
      text-decoration: none;
      font-size: 15px;
      font-weight: 600;
    }
    .mobile-more-sheet__item i {
      width: 20px;
      text-align: center;
      color: #64748b;
    }
    .mobile-more-sheet__item:hover,
    .mobile-more-sheet__item:focus-visible {
      background: #f1f5f9;
      outline: none;
    }
    .mobile-more-sheet__logout {
      display: flex;
      align-items: center;
      gap: 14px;
      width: calc(100% - 8px);
      margin: 8px 4px 0;
      min-height: 48px;
      padding: 0 12px;
      border-radius: 12px;
      border: none;
      background: transparent;
      color: #ef4444;
      font-size: 15px;
      font-weight: 700;
    }
    .mobile-more-sheet__logout:hover,
    .mobile-more-sheet__logout:focus-visible {
      background: #fef2f2;
      outline: none;
    }
  `],
})
export class MobileMoreSheetComponent implements AfterViewChecked {
  @Input() open = false;
  @Input() items: SidebarItem[] = [];
  @Output() close = new EventEmitter<void>();
  @Output() logout = new EventEmitter<void>();

  @ViewChild('closeBtn') closeBtn?: ElementRef<HTMLButtonElement>;
  private focusedOnOpen = false;

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.open) this.close.emit();
  }

  ngAfterViewChecked(): void {
    // Move focus into the sheet the moment it renders; reset the guard once
    // it closes so the next open re-focuses it again.
    if (this.open && this.closeBtn && !this.focusedOnOpen) {
      this.focusedOnOpen = true;
      this.closeBtn.nativeElement.focus();
    } else if (!this.open && this.focusedOnOpen) {
      this.focusedOnOpen = false;
    }
  }

  logoutClick(): void {
    this.close.emit();
    this.logout.emit();
  }
}
