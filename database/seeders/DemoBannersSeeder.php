<?php

namespace Database\Seeders;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Support\Site\BannerDirectory;
use GdImage;
use Illuminate\Database\Seeder;

/**
 * Demo banners for local / staging only (plan 07 7c): two home slider
 * banners with generated placeholder images (GD gradients with shapes, no
 * text: K7) and texts in uz, ru and en. Skipped outside local / staging and
 * when banners already exist. The image conversions run right away (sync),
 * so the slider shows without a queue worker.
 */
class DemoBannersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'staging']) || Banner::query()->exists()) {
            return;
        }

        $queue = config('media-library.queue_connection_name');
        config(['media-library.queue_connection_name' => 'sync']);

        $banners = [
            [
                'colors' => [[23, 128, 69], [12, 74, 110]],
                'link' => '/catalog',
                'title' => [
                    'uz' => 'Uskunani tanlash, sozlash va servis bir joyda',
                    'ru' => 'Подбор, настройка и сервис оборудования в одном месте',
                    'en' => 'Equipment selection, setup and service in one place',
                ],
                'text' => [
                    'uz' => 'Mutaxassislarimiz vazifangizga mos skaner, printer yoki TSDni tanlab, ishga tushirib beradi.',
                    'ru' => 'Наши специалисты подберут сканер, принтер или ТСД под вашу задачу и запустят их в работу.',
                    'en' => 'Our specialists pick the scanner, printer or mobile computer for your task and get it running.',
                ],
                'button_label' => ['uz' => 'Katalogga oʻtish', 'ru' => 'Перейти в каталог', 'en' => 'Open the catalog'],
            ],
            [
                'colors' => [[15, 23, 42], [23, 128, 69]],
                'link' => '/page/warranty-service',
                'title' => [
                    'uz' => 'Rasmiy kafolat va oʻz servis markazimiz',
                    'ru' => 'Официальная гарантия и собственный сервисный центр',
                    'en' => 'Official warranty and our own service centre',
                ],
                'text' => [
                    'uz' => 'Taʼmirlash, profilaktika va texnik yordam: uskunangiz toʻxtab qolmasligi uchun.',
                    'ru' => 'Ремонт, профилактика и техподдержка, чтобы ваше оборудование не простаивало.',
                    'en' => 'Repair, maintenance and support, so your equipment keeps working.',
                ],
                'button_label' => ['uz' => 'Batafsil', 'ru' => 'Подробнее', 'en' => 'Learn more'],
            ],
        ];

        try {
            foreach ($banners as $index => $data) {
                $banner = Banner::query()->create([
                    'placement' => BannerPlacement::HomeHero,
                    'title' => $data['title'],
                    'text' => $data['text'],
                    'button_label' => $data['button_label'],
                    'link' => $data['link'],
                    'is_active' => true,
                    'sort' => $index + 1,
                ]);

                $this->attach($banner, Banner::DESKTOP, Banner::DESKTOP_WIDTH, Banner::DESKTOP_HEIGHT, $data['colors']);
                $this->attach($banner, Banner::MOBILE, Banner::MOBILE_WIDTH, Banner::MOBILE_HEIGHT, $data['colors']);
            }
        } finally {
            config(['media-library.queue_connection_name' => $queue]);
        }

        BannerDirectory::flush();
    }

    /**
     * @param  array{0: array{int, int, int}, 1: array{int, int, int}}  $colors
     */
    private function attach(Banner $banner, string $collection, int $width, int $height, array $colors): void
    {
        $path = tempnam(sys_get_temp_dir(), 'banner').'.jpg';
        imagejpeg($this->placeholder($width, $height, $colors), $path, 85);

        $banner->addMedia($path)
            ->usingFileName($collection.'-'.$banner->id.'.jpg')
            ->withCustomProperties(['alt' => ['uz' => '', 'ru' => '', 'en' => '']])
            ->toMediaCollection($collection);
    }

    /**
     * A diagonal gradient with soft circles on the right (the texts sit on the left).
     *
     * @param  array{0: array{int, int, int}, 1: array{int, int, int}}  $colors
     */
    private function placeholder(int $width, int $height, array $colors): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        [$from, $to] = $colors;

        for ($x = 0; $x < $width; $x++) {
            $ratio = $x / max(1, $width - 1);
            $color = imagecolorallocate(
                $image,
                (int) round($from[0] + ($to[0] - $from[0]) * $ratio),
                (int) round($from[1] + ($to[1] - $from[1]) * $ratio),
                (int) round($from[2] + ($to[2] - $from[2]) * $ratio),
            );
            imageline($image, $x, 0, $x, $height, $color);
        }

        imagealphablending($image, true);
        $light = imagecolorallocatealpha($image, 255, 255, 255, 100);
        $size = (int) round($height * 0.9);
        imagefilledellipse($image, (int) round($width * 0.78), (int) round($height * 0.45), $size, $size, $light);
        imagefilledellipse($image, (int) round($width * 0.92), (int) round($height * 0.85), (int) round($size * 0.6), (int) round($size * 0.6), $light);

        return $image;
    }
}
