<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'substance', 'normalized_substance', 'reaction'])]
class PatientAllergy extends Model {}
