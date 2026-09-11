<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('vremote:health', function () { $this->info('VRemote backend OK'); });
