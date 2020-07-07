<?php

namespace DevDojo\Chatter\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use DB;
use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatterLikes extends Model
{
    //
    use SoftDeletes;
    public $table = 'chatter_post_likes';
    protected $fillable = ['chatter_post_id', 'user_id', 'reaction_id'];

    public function user()
    {
        return $this->belongsTo('App\User')->with('timeline');
    }

    public function reaction()
    {
    	 return $this->belongsTo('App\Reactions');
    }
    
    public static function addLike($data = [])
    {
        if ($data) {
            $data['created_at'] = Carbon::now();
            $data['updated_at'] = Carbon::now();
            $like_id = DB::table('chatter_post_likes')->insertGetId($data); //To insert lakhs of records, direct insert method used instead of ORM based
            $chatterlikes = ChatterLikes::where('id', $like_id)->first();

            return $chatterlikes;
        }
    }
}
