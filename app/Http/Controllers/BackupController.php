<?php

namespace App\Http\Controllers;

use App\Support\Backups;

class BackupController extends Controller
{
    public function download(string $file)
    {
        $path = Backups::path($file);

        abort_if($path === null, 404);

        return response()->download($path);
    }
}
