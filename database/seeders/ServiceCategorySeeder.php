<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Medical Consultation',
                'description' => 'General medical consultation services including checkups, examinations, and primary care visits',
            ],
            [
                'name' => 'Laboratory Tests',
                'description' => 'Blood work, urine tests, stool tests, and other laboratory diagnostic services',
            ],
            [
                'name' => 'Imaging Services',
                'description' => 'X-rays, MRI, CT scans, ultrasound, and other diagnostic imaging services',
            ],
            [
                'name' => 'Emergency Services',
                'description' => 'Urgent medical care, emergency treatment, and immediate response services',
            ],
            [
                'name' => 'Surgical Procedures',
                'description' => 'Minor and major surgical procedures, operations, and surgical interventions',
            ],
            [
                'name' => 'Cardiology Services',
                'description' => 'Heart-related services including EKG, echocardiogram, stress tests, and cardiac procedures',
            ],
            [
                'name' => 'Dermatology Services',
                'description' => 'Skin care, dermatological examinations, mole removal, and skin treatment services',
            ],
            [
                'name' => 'Pediatric Services',
                'description' => 'Medical services specifically for children and infants including vaccinations and pediatric care',
            ],
            [
                'name' => 'Gynecology Services',
                'description' => 'Women\'s health services including pelvic exams, pap smears, and reproductive health care',
            ],
            [
                'name' => 'Ophthalmology Services',
                'description' => 'Eye care services including vision tests, eye examinations, and optical procedures',
            ],
            [
                'name' => 'Dental Services',
                'description' => 'Oral health services including cleanings, fillings, extractions, and dental procedures',
            ],
            [
                'name' => 'Physical Therapy',
                'description' => 'Rehabilitation services, physical therapy sessions, and mobility improvement treatments',
            ],
            [
                'name' => 'Mental Health Services',
                'description' => 'Psychological counseling, therapy sessions, and mental health treatment services',
            ],
            [
                'name' => 'Nutrition Services',
                'description' => 'Dietary consultation, nutrition counseling, and meal planning services',
            ],
            [
                'name' => 'Pharmacy Services',
                'description' => 'Medication dispensing, prescription management, and pharmaceutical consultation',
            ],
        ];

        foreach ($categories as $category) {
            ServiceCategory::create($category);
        }
    }
}
