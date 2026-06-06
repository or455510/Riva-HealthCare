import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import { CUSTOM_ELEMENTS_SCHEMA } from '@angular/core';

@Component({
  selector: 'app-loading',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './loading.component.html',
  styleUrl: './loading.component.css',
  schemas: [CUSTOM_ELEMENTS_SCHEMA],

})
export class LoadingComponent implements OnInit {
  hidden = false;

  constructor(private router: Router) {}

  ngOnInit() {
    setTimeout(() => { this.hidden = true; }, 4000);
  }

 
}