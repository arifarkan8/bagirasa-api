<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Jadwal tugas otomatis BagiRasa
|--------------------------------------------------------------------------
| Menyapu klaim yang lock-nya habis, menutup item kedaluwarsa, dan
| mengirim peringatan lock hampir habis. Berjalan tiap menit.
*/
Schedule::command('bagirasa:rapikan')
    ->everyMinute()
    ->withoutOverlapping();