import { Component } from '@angular/core';

@Component({
  selector: 'app-floating-robot',
  standalone: true,
  template: `
    <div class="floating-container">
      <svg viewBox="0 0 100 100" class="robot-icon">
        <rect x="48" y="5" width="4" height="20" rx="2" fill="#2563EB" />
        <circle cx="50" cy="5" r="4" fill="#2563EB">
          <animate attributeName="r" values="4;5;4" dur="2s" repeatCount="indefinite" />
        </circle>
        
        <rect x="15" y="25" width="70" height="60" rx="20" fill="url(#bot-body-grad)" class="bot-shell" />
        
        <rect x="22" y="38" width="56" height="25" rx="12" fill="#1A1C1E" />
        
        <g class="eyes">
          <circle cx="40" cy="50" r="4" fill="#3B82F6" class="glow">
            <animate attributeName="opacity" values="0.6;1;0.6" dur="3s" repeatCount="indefinite" />
          </circle>
          <circle cx="60" cy="50" r="4" fill="#3B82F6" class="glow">
            <animate attributeName="opacity" values="0.6;1;0.6" dur="3s" repeatCount="indefinite" />
          </circle>
        </g>
        
        <rect x="42" y="70" width="16" height="4" rx="2" fill="rgba(37, 99, 235, 0.4)" />

        <defs>
          <linearGradient id="bot-body-grad" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
            <stop offset="100%" style="stop-color:#F1F4F9;stop-opacity:1" />
          </linearGradient>
          <filter id="eye-glow">
            <feGaussianBlur stdDeviation="2" result="coloredBlur"/>
            <feMerge>
              <feMergeNode in="coloredBlur"/>
              <feMergeNode in="SourceGraphic"/>
            </feMerge>
          </filter>
        </defs>
      </svg>
    </div>
  `,
  styles: [`
    .floating-container {
      position: fixed;
      z-index: 2000;
      pointer-events: none;
      width: 60px;
      height: 60px;
      animation: drift 60s infinite linear alternate, float 6s infinite ease-in-out;
      bottom: 15%;
      right: 5%;
      filter: drop-shadow(0 15px 30px rgba(0, 0, 0, 0.15));
    }

    .robot-icon {
      width: 100%;
      height: 100%;
      overflow: visible;
    }

    .bot-shell {
      stroke: rgba(255, 255, 255, 0.8);
      stroke-width: 0.5;
    }

    .glow {
      filter: drop-shadow(0 0 5px #3B82F6);
    }

    @keyframes float {
      0%, 100% { transform: translateY(0) rotate(-2deg); }
      50% { transform: translateY(-15px) rotate(3deg); }
    }

    @keyframes drift {
      0% { bottom: 10vh; right: 5vw; }
      25% { bottom: 80vh; right: 10vw; }
      50% { bottom: 75vh; right: 80vw; }
      75% { bottom: 15vh; right: 70vw; }
      100% { bottom: 10vh; right: 5vw; }
    }

    @media (max-width: 768px) {
      .floating-container {
        width: 70px;
        height: 70px;
      }
    }
  `]
})
export class FloatingRobotComponent {}
