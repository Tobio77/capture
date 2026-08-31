<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan kunci–nilai yang dapat diubah admin saat aplikasi berjalan.
 * Gunakan App\Support\PengaturanRepository, bukan model ini langsung,
 * agar enkripsi nilai sensitif tidak terlewat.
 */
#[Fillable(['kunci', 'nilai', 'terenkripsi'])]
#[Hidden(['nilai'])]
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terenkripsi' => 'boolean',
        ];
    }
}
