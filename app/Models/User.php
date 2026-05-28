<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'id',
        'name',
        'email',
        'image_path',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected $appends = [
        'image_url',
    ];

    public function storeRoles(): HasMany
    {
        return $this->hasMany(UserStoreRole::class);
    }

    public function hasStoreRole(string $roleName, ?int $storeId = null): bool
    {
        // ✅ super-admin: has everything
        if ($this->isSuperAdmin()) {
            return true;
        }

        $roleName = trim($roleName);
        if ($roleName === '')
            return false;

        $q = $this->storeRoles()
            ->where('active', true)
            ->where('role_name', $roleName);

        if ($storeId === null) {
            return $q->whereNull('store_id')->exists();
        }

        return $q->where(function ($qq) use ($storeId) {
            $qq->whereNull('store_id')->orWhere('store_id', (int) $storeId);
        })->exists();
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->image_path;

        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/') || str_starts_with($path, '/storage/')) {
            return $this->authUrl($path);
        }

        $baseUrl = $this->authBaseUrl();

        if ($baseUrl === '') {
            return url($path);
        }

        return $baseUrl . '/storage/' . ltrim($path, '/');
    }

    protected function authUrl(string $path): string
    {
        $baseUrl = $this->authBaseUrl();

        if ($baseUrl === '') {
            return url($path);
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function authBaseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.auth_server.base_url'), '/');

        if ($baseUrl === '') {
            return '';
        }

        $baseUrl = preg_replace('~/api(?:/.*)?$~i', '', $baseUrl);

        return rtrim((string) $baseUrl, '/');
    }
}
