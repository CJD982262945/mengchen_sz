<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCommunityInvitationApplicationTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('community_invitation_application', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('player_id')->comment('玩家id');
            $table->tinyInteger('type')->comment('类型(0-申请,1-邀请)');
            $table->unsignedInteger('community_id')->comment('社团id');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->foreign('community_id')->references('id')->on('community_list');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('community_invitation_application');
    }
}
