<?php

namespace Database\Seeders;

use BookStack\Users\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateUserJsonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $contents = file_get_contents(base_path('user.json'));
        $json = json_decode(json: $contents, associative: true);

        $slugMap = [];
        foreach(User::all() as $item){
            $slugMap[$item->slug] = true;
        }

        foreach($json as $key => $item){
            if(User::where('email',  $item[0])->first()){
                $user = User::where('email',  $item[0])->first();
                $user->email = $item[0];
                $user->name = $item[1];
                $user->nip_lama = $item[2];
                $user->save();
            }else{
                $userSlug = Str::slug($item[1]);
                while (isset($slugMap[$userSlug])) {
                    $userSlug = Str::slug($item[1] .' '. Str::random(4));
                }
                $slugMap[$userSlug] = true;

                $new_user = new User();
                $new_user->email = $item[0];
                $new_user->name = $item[1];
                $new_user->nip_lama = $item[2];
                $new_user->slug = $userSlug;
                $new_user->save();
            }
        }

        Log::info($json);
    }
}
