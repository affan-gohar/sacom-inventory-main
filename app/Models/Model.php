<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as BaseModel;

/** Base model: all fields are guarded unless a model declares #[Fillable]. */
abstract class Model extends BaseModel {}
