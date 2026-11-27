import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../constants';

export interface AdminResponse<T = any> {
  success: boolean;
  message: string;
  data: T;
}

@Injectable({ providedIn: 'root' })
export class AdminService {
  private readonly baseUrl = `${API_BASE_URL}/admin`;

  constructor(private http: HttpClient) {}

  get<T = any>(path: string, params: Record<string, any> = {}): Observable<AdminResponse<T>> {
    let httpParams = new HttpParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        httpParams = httpParams.set(key, String(value));
      }
    });

    return this.http.get<AdminResponse<T>>(`${this.baseUrl}/${path}`, { params: httpParams });
  }

  post<T = any>(path: string, body: Record<string, any> = {}): Observable<AdminResponse<T>> {
    return this.http.post<AdminResponse<T>>(`${this.baseUrl}/${path}`, body);
  }

  put<T = any>(path: string, body: Record<string, any>): Observable<AdminResponse<T>> {
    return this.http.put<AdminResponse<T>>(`${this.baseUrl}/${path}`, body);
  }

  patch<T = any>(path: string, body: Record<string, any>): Observable<AdminResponse<T>> {
    return this.http.patch<AdminResponse<T>>(`${this.baseUrl}/${path}`, body);
  }
}
