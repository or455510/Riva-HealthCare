<?php

namespace App\Http\Controllers;

use App\Models\Disease;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DiseaseController extends Controller
{
    public function index()
    {
        return $this->success(Disease::latest()->get());
    }

    public function show(string $slug)
    {
        return $this->success(Disease::where('slug', $slug)->firstOrFail());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:diseases,slug'],
            'overview' => ['nullable', 'string'],
            'symptoms' => ['nullable', 'string'],
            'causes' => ['nullable', 'string'],
            'treatment_info' => ['nullable', 'string'],
            'tips' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $disease = Disease::create([
            ...$data,
            'slug' => $data['slug'] ?? Str::slug($data['title']),
        ]);

        return $this->success($disease, 'Disease content created successfully', 201);
    }

    public function update(Request $request, Disease $disease)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'unique:diseases,slug,'.$disease->id],
            'overview' => ['nullable', 'string'],
            'symptoms' => ['nullable', 'string'],
            'causes' => ['nullable', 'string'],
            'treatment_info' => ['nullable', 'string'],
            'tips' => ['nullable', 'string'],
            'image' => ['nullable', 'string', 'max:255'],
        ]);

        $disease->update($data);
        return $this->success($disease->fresh(), 'Disease content updated successfully');
    }

    public function destroy(Disease $disease)
    {
        $disease->delete();
        return $this->success(null, 'Disease content deleted successfully');
    }
}
