<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Registrasi pengguna baru.
     * Kolom role dan status_akun sengaja tidak diterima dari input.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'no_whatsapp' => ['required', 'string', 'regex:/^(08|62)[0-9]{8,13}$/'],
            'area' => ['required', 'string', 'max:100'],
            'setuju_kebijakan' => ['accepted'],
        ], [
            'no_whatsapp.regex' => 'Nomor WhatsApp harus diawali 08 atau 62 dan hanya berisi angka.',
            'setuju_kebijakan.accepted' => 'Anda harus menyetujui kebijakan privasi.',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'no_whatsapp' => $data['no_whatsapp'],
            'area' => $data['area'],
        ]);

        // Muat ulang agar nilai default dari database (role, status_akun) ikut terbaca.
        $user->refresh();

        ActivityLog::catat('akun_dibuat', $user->id, 'user', $user->id, null, $request->ip());

        $token = $user->createToken('api-token', ['*'], now()->addDays(7))->plainTextToken;

        return response()->json([
            'message' => 'Registrasi berhasil.',
            'user' => $this->dataUser($user),
            'token' => $token,
        ], 201);
    }

    /**
     * Login dan penerbitan token bearer.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Email atau kata sandi salah.',
            ], 422);
        }

        if ($user->status_akun === 'diblokir') {
            return response()->json([
                'message' => 'Akun Anda diblokir. Hubungi admin.',
            ], 403);
        }

        $token = $user->createToken('api-token', ['*'], now()->addDays(7))->plainTextToken;

        ActivityLog::catat('login', $user->id, 'user', $user->id, null, $request->ip());

        return response()->json([
            'message' => 'Login berhasil.',
            'user' => $this->dataUser($user),
            'token' => $token,
        ]);
    }

    /**
     * Logout: hapus token yang sedang dipakai.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    /**
     * Data profil pengguna yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->dataUser($request->user())]);
    }

    /**
     * Ubah profil (nama, WhatsApp, area).
     */
    public function updateProfil(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'no_whatsapp' => ['sometimes', 'required', 'string', 'regex:/^(08|62)[0-9]{8,13}$/'],
            'area' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        $request->user()->update($data);

        return response()->json([
            'message' => 'Profil diperbarui.',
            'user' => $this->dataUser($request->user()->fresh()),
        ]);
    }

    private function dataUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'no_whatsapp' => $user->no_whatsapp,
            'area' => $user->area,
            'role' => $user->role,
            'status_akun' => $user->status_akun,
            'dibatasi_sampai' => $user->dibatasi_sampai,
        ];
    }
}