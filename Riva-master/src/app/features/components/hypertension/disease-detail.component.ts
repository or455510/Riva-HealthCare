import { Component, HostListener, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { DISEASE_DETAILS, DiseaseDetail, normalizeDiseaseSlug } from './disease-details.data';

@Component({
  selector: 'app-disease-detail',
  standalone: true,
  imports: [CommonModule, RouterLink],
  templateUrl: './disease-detail.component.html',
  styleUrls: ['./disease-detail.component.scss'],
})
export class DiseaseDetailComponent {
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  isMenuOpen = false;
  isScrolled = false;
  disease: DiseaseDetail = DISEASE_DETAILS['heart-disease'];

  ngOnInit(): void {
    this.route.paramMap.subscribe((params) => {
      const slug = normalizeDiseaseSlug(params.get('slug') || this.route.snapshot.data['diseaseSlug']);
      this.disease = DISEASE_DETAILS[slug];
    });
  }

  @HostListener('window:scroll')
  onWindowScroll(): void {
    this.isScrolled = window.scrollY > 12;
  }

  toggleMenu(): void {
    this.isMenuOpen = !this.isMenuOpen;
  }

  goBackHome(): void {
    this.router.navigate(['/home'], { fragment: 'diseases' });
  }

  onImageError(event: Event): void {
    const img = event.target as HTMLImageElement;
    img.src = '/images.jpg';
  }
}