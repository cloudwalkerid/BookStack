<?php

namespace BookStack\Access\Controllers;

use BookStack\Http\Controller;
use BookStack\Access\LoginService;
use BookStack\Access\SocialDriverManager;
use BookStack\App\Providers\RouteServiceProvider;
use BookStack\Uploads\ImageRepo;
use BookStack\Users\Models\User;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JKD\SSO\Client\Provider\Keycloak;

class SSOController extends Controller
{
    private $provider;

    public function __construct(
        protected SocialDriverManager $socialDriverManager,
        protected LoginService $loginService,
    )
    {
        $this->middleware('guest', ['only' => ['getLogin', 'login']]);
        $this->middleware('guard:standard,ldap', ['only' => ['login']]);
        $this->middleware('guard:standard,ldap,oidc', ['only' => ['logout']]);

        // $this->middleware('guest')->except('logout');
        $this->provider = new Keycloak([
            'authServerUrl'         => env('SSO_Url'),
            'realm'                 => env('SSO_Realm'),
            'clientId'              => env('Client_Id'),
            'clientSecret'          => env('Client_Secret'),
            'redirectUri'           => env('Client_URL')
        ]);
    }

    public function showLoginForm()
    {
        return view('auth.login_sso');
    }

    public function sso(Request $request, ImageRepo $imageRepo)
    {
        if(Auth::check()){
            return redirect('/');
        }
        if (!$request->input('code')) {
            // Untuk mendapatkan authorization code
            $authUrl = $this->provider->getAuthorizationUrl();
            $request->session()->put('oauth2state', $this->provider->getState());
            return Redirect::to($authUrl);
        }  else if (!$request->has('state') || ($request->get('state') !== $request->session()->get('oauth2state'))) {
            
            $request->session()->forget('oauth2state');
            return redirect('/login')->withErrors(['user' => 'Terjadi kesalahan']);

        } else {
            try {
                $token = $this->provider->getAccessToken('authorization_code', [
                    'code' => $request->get('code')
                ]);
            } catch (Exception $e) {
                Log::error('Gagal mendapatkan akses token : '.$e->getMessage());
                return redirect('/login')->withErrors(['user' => 'Terjadi kesalahan']);
            }


            $data_owner = $this->provider->getResourceOwner($token);
            $data_owner_arr = $data_owner->toArray();


            if(str_starts_with($data_owner_arr['organisasi'], '7600')){
                if(User::where('email',  $data_owner_arr['email'])->first()){
                    $user = User::where('email',  $data_owner_arr['email'])->first();
                    $user->email = $data_owner_arr['email'];
                    $user->name = $data_owner_arr['name'];
                    $user->nip_lama = $data_owner_arr['nip-lama'];
                    $user->save();

                    if(Auth::loginUsingId($user->id, true)){
                        $imageData = file_get_contents("https://simpeg.bps.go.id/apis/pegawai/avatar/".$data_owner_arr['nip-lama']);
                        $info = pathinfo("https://simpeg.bps.go.id/apis/pegawai/avatar/".$data_owner_arr['nip-lama']);
                        if ($imageData !== false) {
                            Storage::disk('local_secure_temp')->put($info['basename'], $imageData);
                            $uploaded_file = new UploadedFile( Storage::disk('local_secure_temp')->path($info['basename']), $info['basename']);

                            $imageRepo->destroyImage($user->avatar);
                            $image = $imageRepo->saveNew($uploaded_file, 'user', $user->id);
                            $user->image_id = $image->id;
                            $user->save();
                        }

                        return redirect()->intended('/');
                    }
                }else{
                    $userSlug = Str::slug($data_owner_arr['name']);
                    while (User::where('slug',  $userSlug)->first()) {
                        $userSlug = Str::slug($data_owner_arr['name'] .' '. Str::random(4));
                    }

                    $new_user = new User();
                    $new_user->email = $data_owner_arr['email'];
                    $new_user->name = $data_owner_arr['name'];
                    $new_user->nip_lama = $data_owner_arr['nip-lama'];
                    $new_user->slug = $userSlug;
                    $new_user->save();

                    if(Auth::loginUsingId($new_user->id, true)){
                        $imageData = file_get_contents("https://simpeg.bps.go.id/apis/pegawai/avatar/".$data_owner_arr['nip-lama']);
                        $info = pathinfo("https://simpeg.bps.go.id/apis/pegawai/avatar/".$data_owner_arr['nip-lama']);
                        if ($imageData !== false) {
                            Storage::disk('local_secure_temp')->put($info['basename'], $imageData);
                            $uploaded_file = new UploadedFile( Storage::disk('local_secure_temp')->path($info['basename']), $info['basename']);

                            $imageRepo->destroyImage($new_user->avatar);
                            $image = $imageRepo->saveNew($uploaded_file, 'user', $new_user->id);
                            $new_user->image_id = $image->id;
                            $new_user->save();
                        }

                        return redirect()->intended('/');
                    }
                }
            }else{
                User::where('email',  $data_owner_arr['email'])->delete();
            }
        }
    }

    public function logout(Request $request)
    {
        $request->session()->forget('oauth2state');

        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

}
