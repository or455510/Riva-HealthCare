import { CommonModule } from '@angular/common';
import { Component, AfterViewInit, NgZone, OnDestroy } from '@angular/core';
import { Router } from '@angular/router';

interface HealthTip {
  title: string;
  text: string;
}

interface Testimonial {
  name: string;
  role: 'Patient' | 'Doctor' | 'Caregiver' | 'Family Member';
  rating: number;
  comment: string;
  avatar?: string;
}

interface AboutTab {
  title: string;
  shortTitle: string;
  description: string;
  icon: string;
  images: { src: string; alt: string }[];
  animationClass: string;
}

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './home.component.html',
  styleUrls: ['./home.component.css'],
})
export class HomeComponent implements AfterViewInit, OnDestroy {

  healthTips: HealthTip[] = [
    { title: 'Stay Hydrated!', text: 'Drinking 8 glasses of water daily helps regulate blood pressure.' },
    { title: 'Check Your Sugar', text: 'Regular monitoring helps you understand your body better.' },
    { title: 'Walk Regularly', text: 'A 15-minute walk after meals improves digestion significantly.' },
    { title: 'Sleep Well', text: '7-8 hours of sleep helps manage stress hormones.' },
  ];

  currentIndex = 0;
  activeAboutIndex = 0;
  currentReviewIndex = 0;
  reviewAnimationKey = 0;
  readonly stars = [1, 2, 3, 4, 5];
  private reviewTimer?: ReturnType<typeof setInterval>;

  aboutTabs: AboutTab[] = [
    {
      title: 'Personalized care just for you.',
      shortTitle: 'Patient Monitoring',
      description: 'Riva helps patients follow daily readings, medication schedules, symptoms, and medical reports in one clear place. Doctors and caregivers can see meaningful updates without overwhelming the patient, making long-term monitoring feel simple and personal.',
      icon: 'image/robot1.jpeg',
      images: [
        { src: 'image/slide3.jpeg', alt: 'Doctor reviewing a patient care plan' },
        { src: 'image/slide6.jpeg', alt: 'Patient consultation in a modern clinic' },
      ],
      animationClass: 'about-patient-motion',
    },
    {
      title: 'Simple technology for great results.',
      shortTitle: 'Smart Automation',
      description: 'Smart reminders, alerts, organized reports, and automated follow-up flows reduce repeated manual work. Riva turns health data into timely actions so patients remember care tasks and clinical teams can focus on decisions.',
      icon: 'image/robot2.jpeg',
      images: [
        { src: 'image/slide1.jpeg', alt: 'Healthcare dashboard and monitoring technology' },
        { src: 'image/slide8.jpeg', alt: 'Smart medical automation interface' },
      ],
      animationClass: 'about-tech-motion',
    },
    {
      title: 'Peace of mind for those you love.',
      shortTitle: 'Family Support',
      description: 'Caregivers and family members can stay informed through medication completion updates, emergency alerts, and patient progress summaries. Riva keeps support connected while respecting the patient journey.',
      icon: 'image/robot3.jpeg',
      images: [
        { src: 'image/slide5.jpeg', alt: 'Caregiver supporting an elderly patient' },
        { src: 'image/slide10.jpeg', alt: 'Family healthcare support and follow-up' },
      ],
      animationClass: 'about-family-motion',
    },
  ];

  reviews: Testimonial[] = [
    {
      name: 'Mariam Hassan',
      role: 'Patient',
      rating: 5,
      comment: 'Riva helped me remember my medication on time and made daily follow-up easier.',
      avatar: 'image/avatar4.jpeg',
    },
    {
      name: 'Omar Nabil',
      role: 'Caregiver',
      rating: 5,
      comment: 'As a caregiver, I can quickly check whether my father completed his medication schedule.',
      avatar: 'image/avatar2.jpeg',
    },
    {
      name: 'Dr. Salma Adel',
      role: 'Doctor',
      rating: 5,
      comment: 'The patient reports help me understand daily symptoms before the appointment.',
      avatar: 'image/avatar3.jpeg',
    },
    {
      name: 'Layla Mahmoud',
      role: 'Patient',
      rating: 5,
      comment: 'The interface is simple and clear, especially for elderly patients.',
      avatar: 'image/avtar1.jpeg',
    },
    {
      name: 'Karim Youssef',
      role: 'Family Member',
      rating: 5,
      comment: 'Emergency alerts and notifications make the care process more reliable for our family.',
    },
    {
      name: 'Dr. Hany Morgan',
      role: 'Doctor',
      rating: 5,
      comment: 'The chat and medical reports keep communication organized between patient and doctor.',
    },
    {
      name: 'Nour Ahmed',
      role: 'Caregiver',
      rating: 5,
      comment: 'Riva gives me peace of mind when I cannot be beside my mother all day.',
    },
    {
      name: 'Youssef Sami',
      role: 'Patient',
      rating: 5,
      comment: 'Uploading lab results and sharing them before visits saves time and avoids confusion.',
    },
  ];

  constructor(private zone: NgZone, private router: Router) {}

  nextTip(): void {
    const tipContent = document.querySelector('.tip-banner .flex-1') as HTMLElement;
    if (tipContent) {
      tipContent.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
      tipContent.style.opacity = '0';
      tipContent.style.transform = 'translateY(10px)';
      setTimeout(() => {
        this.currentIndex = (this.currentIndex + 1) % this.healthTips.length;
        setTimeout(() => {
          tipContent.style.opacity = '1';
          tipContent.style.transform = 'translateY(0)';
        }, 20);
      }, 260);
    } else {
      this.currentIndex = (this.currentIndex + 1) % this.healthTips.length;
    }
  }

  get currentTip(): HealthTip {
    return this.healthTips[this.currentIndex];
  }

  get activeAboutTab(): AboutTab {
    return this.aboutTabs[this.activeAboutIndex];
  }

  get currentTestimonials(): Testimonial[] {
    return [0, 1, 2].map((offset) => this.reviews[(this.currentReviewIndex + offset) % this.reviews.length]);
  }

  getInitials(name: string): string {
    return name
      .split(' ')
      .map((part) => part.charAt(0))
      .join('')
      .slice(0, 2)
      .toUpperCase();
  }

  nextReview(): void {
    this.currentReviewIndex = (this.currentReviewIndex + 1) % this.reviews.length;
    this.reviewAnimationKey++;
  }

  prevReview(): void {
    this.currentReviewIndex = (this.currentReviewIndex - 1 + this.reviews.length) % this.reviews.length;
    this.reviewAnimationKey++;
  }

  goToReview(index: number): void {
    this.currentReviewIndex = index;
    this.reviewAnimationKey++;
    this.startReviewAutoSlide();
  }

  selectAboutTab(index: number): void {
    if (index === this.activeAboutIndex) return;
    this.activeAboutIndex = index;
  }

  pauseReviews(): void {
    this.stopReviewAutoSlide();
  }

  resumeReviews(): void {
    this.startReviewAutoSlide();
  }

  goToSignin(): void {
    this.router.navigate(['/signin']);
  }

  goToContact(): void {
    this.router.navigate(['/contact']);
  }

  goToDisease(disease: string): void {
    this.router.navigate(['/diseases', disease]);
  }

  ngAfterViewInit(): void {
    this.zone.runOutsideAngular(() => {
      this.initScrollReveal();
      this.initMarqueeHover();
      this.initCardTilt();
      this.initMagneticButtons();
      this.initStepConnector();
    });
    this.startReviewAutoSlide();
  }

  ngOnDestroy(): void {
    this.stopReviewAutoSlide();
  }

  private startReviewAutoSlide(): void {
    this.stopReviewAutoSlide();
    this.reviewTimer = setInterval(() => {
      this.zone.run(() => this.nextReview());
    }, 4000);
  }

  private stopReviewAutoSlide(): void {
    if (this.reviewTimer) {
      clearInterval(this.reviewTimer);
      this.reviewTimer = undefined;
    }
  }

  private initScrollReveal(): void {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.10, rootMargin: '0px 0px -40px 0px' }
    );

    document.querySelectorAll(
      '.reveal, .reveal-left, .about-tab-card, .review-card, .step-item, .section-heading'
    ).forEach((el) => observer.observe(el));
  }

  private initMarqueeHover(): void {
    document.querySelectorAll('.animate-marquee').forEach((marquee) => {
      const parent = marquee.parentElement;
      if (!parent) return;
      const el = marquee as HTMLElement;
      parent.addEventListener('mouseenter', () => { el.style.animationPlayState = 'paused'; });
      parent.addEventListener('mouseleave', () => { el.style.animationPlayState = 'running'; });
    });
  }

  private initCardTilt(): void {
    const TILT_MAX = 8;
    const cards = document.querySelectorAll<HTMLElement>('.health-card, .testimonial-card');

    cards.forEach((card) => {
      card.addEventListener('mousemove', (e: MouseEvent) => {
        const rect = card.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width  - 0.5;
        const y = (e.clientY - rect.top)  / rect.height - 0.5;
        const rotX = (-y * TILT_MAX).toFixed(2);
        const rotY = ( x * TILT_MAX).toFixed(2);
        card.style.transform =
          `translateY(-14px) scale(1.02) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
        card.style.transition = 'transform 0.08s linear, box-shadow 0.08s linear';
      });

      card.addEventListener('mouseleave', () => {
        card.style.transform = '';
        card.style.transition = '';
      });
    });
  }

  private initMagneticButtons(): void {
    const PULL_STRENGTH = 0.35;
    document.querySelectorAll<HTMLElement>('.hero-cta button:first-child, .tip-btn')
      .forEach((btn) => {
        btn.addEventListener('mousemove', (e: MouseEvent) => {
          const rect = btn.getBoundingClientRect();
          const cx = rect.left + rect.width / 2;
          const cy = rect.top  + rect.height / 2;
          const dx = (e.clientX - cx) * PULL_STRENGTH;
          const dy = (e.clientY - cy) * PULL_STRENGTH;
          btn.style.transform = `translate(${dx}px, ${dy}px) scale(1.06)`;
          btn.style.transition = 'transform 0.15s ease, box-shadow 0.15s ease';
        });

        btn.addEventListener('mouseleave', () => {
          btn.style.transform = '';
          btn.style.transition = '';
        });
      });
  }

  private initStepConnector(): void {
    const stepsWrapper = document.querySelector<HTMLElement>('.py-16.bg-white .flex.flex-col');
    if (!stepsWrapper) return;
    if (window.innerWidth < 768) return;

    const line = document.createElement('div');
    line.style.cssText = `
      position: absolute;
      top: 32px; left: 10%; right: 10%;
      height: 2px;
      background: linear-gradient(90deg, #e0f2fe, #38bdf8, #2563eb, #38bdf8, #e0f2fe);
      background-size: 200% 100%;
      border-radius: 2px;
      animation: lineFlow 3s linear infinite;
      pointer-events: none;
      z-index: 0;
    `;

    const styleEl = document.createElement('style');
    styleEl.textContent = `
      @keyframes lineFlow {
        0%   { background-position: 200% 0; }
        100% { background-position: -200% 0; }
      }
    `;
    document.head.appendChild(styleEl);

    stepsWrapper.style.position = 'relative';
    stepsWrapper.insertBefore(line, stepsWrapper.firstChild);
  }
}
