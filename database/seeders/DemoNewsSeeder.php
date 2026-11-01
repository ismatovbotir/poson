<?php

namespace Database\Seeders;

use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\DemoContent;
use App\Support\Site\NewsDirectory;
use Illuminate\Database\Seeder;

/**
 * Demo news for local / staging only (plan 07 7b), so the news list, the
 * home section, RSS and the admin screens have data: two published posts
 * (one in uz and ru only) and one scheduled for next week, linked to the
 * first demo products and categories. Skipped outside local / staging
 * (unless LaunchSeeder opts in, DemoSeeding, plan 15) and when news posts
 * (kind news; seeded articles don't count) already exist. The slugs identify
 * the demo posts for `php artisan demo:remove` (App\Support\DemoContent).
 * No cover images (upload them in admin).
 */
class DemoNewsSeeder extends Seeder
{
    public function run(): void
    {
        if (! DemoSeeding::allowed() || DemoSeeding::blockedInProduction() || NewsPost::query()->ofKind(NewsKind::News)->exists()) {
            return;
        }

        $products = Product::query()->where('is_active', true)->orderBy('id')->limit(4)->pluck('id')->all();
        $categories = Category::query()->whereNull('parent_id')->orderBy('sort')->limit(2)->pluck('id')->all();

        $posts = [
            [
                'slug' => 'servis-markazi-ish-vaqti',
                'published_at' => now()->subDays(10)->setTime(9, 0),
                'title' => [
                    'uz' => 'Servis markazi endi shanba kunlari ham ishlaydi',
                    'ru' => 'Сервисный центр теперь работает и по субботам',
                    'en' => 'Our service centre is now open on Saturdays',
                ],
                'excerpt' => [
                    'uz' => 'Skaner, printer va TSDni taʼmirlash hamda sozlash uchun shanba kunlari ham qabul qilamiz.',
                    'ru' => 'Принимаем сканеры, принтеры и ТСД на ремонт и настройку и по субботам.',
                    'en' => 'Scanners, printers and mobile computers are now accepted for repair and setup on Saturdays too.',
                ],
                'body' => [
                    'uz' => '<p>Poson servis markazi shanba kunlari ham ishlaydi. Kafolatli va kafolatdan keyingi taʼmirlash, profilaktika va sozlash uchun uskunangizni olib keling.</p><h2>Nimalarni qabul qilamiz</h2><ul><li>Shtrix-kod skanerlari</li><li>Yorliq va chek printerlari</li><li>Maʼlumot yigʻish terminallari (TSD)</li></ul>',
                    'ru' => '<p>Сервисный центр Poson теперь работает и по субботам. Привозите оборудование на гарантийный и постгарантийный ремонт, профилактику и настройку.</p><h2>Что принимаем</h2><ul><li>Сканеры штрих-кода</li><li>Принтеры этикеток и чеков</li><li>Терминалы сбора данных (ТСД)</li></ul>',
                    'en' => '<p>The Poson service centre is now open on Saturdays. Bring your equipment for warranty and post-warranty repair, maintenance and setup.</p><h2>What we accept</h2><ul><li>Barcode scanners</li><li>Label and receipt printers</li><li>Mobile computers</li></ul>',
                ],
                'products' => array_slice($products, 0, 2),
                'categories' => array_slice($categories, 0, 1),
            ],
            [
                'slug' => '1c-bilan-integratsiya',
                'published_at' => now()->subDays(3)->setTime(11, 30),
                'title' => [
                    'uz' => 'TSD va 1C: omborni inventarizatsiya qilish bir kunda',
                    'ru' => 'ТСД и 1С: инвентаризация склада за один день',
                ],
                'excerpt' => [
                    'uz' => 'Mutaxassislarimiz TSDni 1C bilan ulaydi va xodimlaringizni oʻqitadi.',
                    'ru' => 'Наши специалисты подключат ТСД к 1С и обучат ваших сотрудников.',
                ],
                'body' => [
                    'uz' => '<p>Maʼlumot yigʻish terminali 1C bilan toʻgʻri ulansa, inventarizatsiya bir necha soatda tugaydi. Biz uskunani tanlaymiz, sozlaymiz va ombor jamoangizni oʻqitamiz.</p>',
                    'ru' => '<p>Если терминал сбора данных правильно подключён к 1С, инвентаризация занимает считаные часы. Мы подберём оборудование, настроим его и обучим команду склада.</p>',
                ],
                'products' => array_slice($products, 2, 2),
                'categories' => array_slice($categories, 1, 1),
            ],
            [
                'slug' => 'yangi-yorliq-printerlari',
                'published_at' => now()->addWeek()->setTime(10, 0),
                'title' => [
                    'uz' => 'Omborda yangi yorliq printerlari',
                    'ru' => 'На складе новые принтеры этикеток',
                    'en' => 'New label printers in stock',
                ],
                'excerpt' => [
                    'uz' => 'Rejalashtirilgan nashr namunasi: keyingi haftada eʼlon qilinadi.',
                    'ru' => 'Пример запланированной публикации: выйдет на следующей неделе.',
                    'en' => 'A scheduled post example: goes live next week.',
                ],
                'body' => [
                    'uz' => '<p>Yangi yorliq printerlari omborda. Mutaxassislarimiz vazifangizga mos modelni tanlab, oʻrnatib beradi.</p>',
                    'ru' => '<p>Новые принтеры этикеток уже на складе. Наши специалисты подберут модель под вашу задачу и установят её.</p>',
                    'en' => '<p>New label printers are in stock. Our specialists will pick the model for your task and install it.</p>',
                ],
                'products' => array_slice($products, 0, 1),
                'categories' => [],
            ],
        ];

        $ids = [];

        foreach ($posts as $data) {
            $post = NewsPost::query()->create([
                'slug' => $data['slug'],
                'status' => NewsStatus::Published,
                'published_at' => $data['published_at'],
                'title' => $data['title'],
                'excerpt' => $data['excerpt'],
                'body' => $data['body'],
            ]);

            // DatabaseSeeder runs WithoutModelEvents: set what the saving hook would.
            $post->forceFill(['locales' => $post->translatedLocales(), 'content_updated_at' => $data['published_at']])->save();
            $post->products()->sync($data['products']);
            $post->categories()->sync($data['categories']);
            $ids[] = $post->id;
        }

        DemoContent::remember('news_ids', $ids);

        NewsDirectory::flushAll();
    }
}
