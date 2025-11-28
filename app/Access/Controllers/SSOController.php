<?php

namespace BookStack\Access\Controllers;

use BookStack\Http\Controller;
use BookStack\Access\LoginService;
use BookStack\Access\SocialDriverManager;
use BookStack\App\Providers\RouteServiceProvider;
use BookStack\Users\Models\User;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Exception;
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

    public function sso(Request $request)
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
                        return redirect()->intended(RouteServiceProvider::HOME);
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
                        return redirect()->intended(RouteServiceProvider::HOME);
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
