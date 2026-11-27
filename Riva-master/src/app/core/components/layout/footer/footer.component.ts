import { Component } from '@angular/core';
import { RouterModule } from '@angular/router';
import { CommonModule } from '@angular/common';

@Component({
  selector: 'app-footer',
  imports: [RouterModule, CommonModule],
  templateUrl: './footer.component.html',
  styleUrl: './footer.component.css',
})
export class FooterComponent {

  private readonly phone = '201012077945';
  showContactModal = false;

  scrollToSection(sectionId: string): void {
    const element = document.getElementById(sectionId);
    if (element) {
      const navbarOffset = 80;
      const elementPosition = element.getBoundingClientRect().top + window.scrollY;
      const offsetPosition = elementPosition - navbarOffset;
      window.scrollTo({ top: offsetPosition, behavior: 'smooth' });
    }
  }

  openContactOptions(): void {
    this.showContactModal = true;
  }

  closeModal(): void {
    this.showContactModal = false;
  }

  callDirect(): void {
    window.location.href = `tel:+${this.phone}`;
    this.closeModal();
  }

  openWhatsApp(): void {
    const message = encodeURIComponent('مرحباً، أريد التواصل معكم عبر Riva Platform.');
    window.open(`https://wa.me/${this.phone}?text=${message}`, '_blank');
    this.closeModal();
  }
}