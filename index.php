<?php

// Kickstart the framework
$f3=require('lib/base.php');
session_start();

if ((float)PCRE_VERSION<7.9)
        trigger_error('PCRE version is out of date');

// Load configuration
$f3->config('config.ini');
$f3->set('DB', new \DB\SQL(  $f3->get('db.dsn'),
                    $f3->get('db.user'),
                    $f3->get('db.password')
                ));

\Template::instance()->extend('json',function($node){
    $attr = $node['@attrib'];
    $data = \Template::instance()->token($attr['from']);
	return '<?php echo json_encode('.$data.'); ?>';
	/*
	array(1) {
		["@attrib"]=> array(1) {
			["src"]=> string(25) "{{'ui/images/'.@article.image}}"
		}
	}
	*/
});
function base_url() {
    return F3::get('SCHEME').'://'.F3::get('HOST');
}
function sql_now() {
    return date('Y-m-d H:i:s');
}
function guid() {
    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
}
function dbm($model) {
    return new \DB\SQL\Mapper(F3::get('DB'), $model);
}
function render_json($json) {
    header("Content-Type: application/json", true);
    echo json_encode($json);
}
class LibreSplit {
    function __construct() {
    }
    function render_layout($content) {
        F3::set('content', $content);
        echo \Template::instance()->render('layout.htm');
    }
    
    function index() {
        if ($_SESSION['userid']) {
            F3::reroute('/groups');
        } else {
            $this->render_layout('frontpage.htm');
        }
    }
    function login() {
        if ($_GET["identity"]) {
            $openid=new \Web\OpenID;
            $openid->identity=$_GET["identity"];
            $openid->localidentity="http://specs.openid.net/auth/2.0/identifier_select";
            $openid->return_to=base_url().'/verified';
            if ($openid->auth() === FALSE) {
                F3::set('login_error', "This is not a valid openid");
            } else {
                return;
            }
        }
        $this->render_layout('frontpage.htm');
        
    }
    function openid_verified() {
        $openid=new \Web\OpenID;
        //var_dump($openid->response());
        if (!$openid->verified()) {
                F3::set('login_error', "Some error occured while logging in");
                
        //var_dump($openid->response());
            $this->render_layout('frontpage.htm');
            return;
        }
        
        $user = dbm('user');
        $user->load(['openid = ?', $openid->identity]);
        
        if ($user->dry()) { // first login by this openid
            $user->openid=$openid->identity;
            $user->created_at=sql_now();
            $user->save();
            
        }
        
        $_SESSION["userid"] = $user->id;
        $_SESSION["username"] = $user->username;
        $_SESSION["email"] = $user->email;
        $_SESSION["oid"]=$openid->response();
        
        if (isset($_SESSION['loginreturn'])) {
            F3::reroute($_SESSION['loginreturn']);
            unset($_SESSION['loginreturn']);
        } elseif (!$user->email) {
            F3::reroute('/profile?msg=email&back='.urlencode($_SESSION['loginreturn']));
        } else {
            $ug = dbm('group_member');
            if (1 === $ug->count(['user_id = ?', $user->id])) {
                $ug->load(['user_id = ?', $user->id]);
                F3::reroute('/group/' . $ug->group_id);
            } else {
                F3::reroute('/groups');
            }
        }
        
    }
    function logout() {
        unset($_SESSION["userid"]);
        unset($_SESSION["username"]);
        unset($_SESSION["email"]);
        F3::reroute('/');
    }
    function require_login($require_email=TRUE) {
        if (!$_SESSION["userid"]) {
            $_SESSION['loginreturn'] = F3::get('PATH');
            F3::reroute('/login');
            exit;
        }
        if ($require_email && !$_SESSION["email"]) {
            $_SESSION['loginreturn'] = F3::get('PATH');
            F3::reroute('/profile?msg=email');
            exit;
        }
    }
    function require_group($guid) {
        $g = dbm('group');
        $g->load(['id=?', $guid]);
        if ($g->dry()) F3::error(404);
        
        $gm = dbm('group_member');
        $gm->load(['user_id=? AND group_id=?', $_SESSION['userid'], $g->id]);
        if ($gm->dry()) F3::error(404);
        
        return $g;
    }
    function show_profile() {
        $this->require_login(FALSE);
        $user = dbm('user');
        $user->load(['id=?', $_SESSION['userid']]);
        if ($user->dry()) F3::error(404);
        F3::set('profile', $user);
        var_dump($_SESSION["oid"]);
        
        $this->render_layout('profile.htm');
    }
    function update_profile() {
        $this->require_login(FALSE);
        $user = dbm('user');
        $user->load(['id=?', $_SESSION['userid']]);
        if ($user->dry()) F3::error(404);
        
        foreach ([ 'username', 'email' ] as $field)
            if (isset($_POST[$field])) {
                $user[$field] = $_POST[$field];
                $_SESSION[$field] = $_POST[$field];
            }
        
        $user->save();
        
        F3::reroute('/profile');
    }
    function show_groups() {
        $this->require_login();
        $groups = F3::get('DB')->exec('SELECT * 
            FROM group_member INNER JOIN `group` g ON group_id=g.id 
            WHERE user_id = ?', [ $_SESSION['userid'] ]);
        
        F3::set('groups', $groups);
        $this->render_layout('groups.htm');
    }
    function create_group() {
        $this->require_login();
        
        $g = dbm('group');
        $g->id = guid();
        $g->name = $_POST['name'];
        $g->save();
        
        $gm = dbm('group_member');
        $gm->group_id = $g->id;
        $gm->user_id = $_SESSION['userid'];
        $gm->created_at = sql_now();
        $gm->joined_at = sql_now();
        $gm->save();
        
        F3::reroute('/group/' . $g->id);
    }
    function show_group_expenses() {
        $this->require_login();
        
        $g = $this->require_group(F3::get('PARAMS.id'));
        F3::set('group', $g);
        
        $e = F3::get('DB')->exec('SELECT e.*, GROUP_CONCAT(IF(u.id IS NULL,gm.invited_name,concat(u.username, " <",u.email,">")) separator ", ") split_members
        FROM expense e LEFT OUTER JOIN expense_split_user uu ON e.id=uu.expense_id
        LEFT OUTER JOIN group_member gm ON uu.member_id=gm.id
        LEFT OUTER JOIN user u ON gm.user_id=u.id
        WHERE e.group_id = ?
        GROUP BY e.id', [$g->id]);
        F3::set('expenses', $e);
        
        $this->render_layout('group.htm');
    }
    function show_topay() {
        $this->require_login();
        
        $g = $this->require_group(F3::get('PARAMS.id'));
        
        $paids = F3::get('DB')->exec('SELECT sum(e.amount) credit,e.who_paid member_id
        FROM expense e
        WHERE e.group_id = ?
        GROUP BY e.who_paid', [$g->id]);
        $splits = F3::get('DB')->exec('SELECT -(sum(e.amount)*esu.weight)/e.weight_sum credit, esu.member_id
        FROM (
            select ee.*, sum(eesu.weight) weight_sum from expense ee left outer join expense_split_user eesu on ee.id=eesu.expense_id
             WHERE ee.group_id = ? group by ee.id
        ) AS e 
        inner join expense_split_user AS esu 
                    on e.id=esu.expense_id
        
        GROUP BY esu.member_id', [$g->id]);
        $m = [];
        foreach($paids as $d) $m[ $d['member_id'] ] += $d['credit'];
        foreach($splits as $d) $m[ $d['member_id'] ] += $d['credit'];
        render_json(["success" => true, "to_pay" => $m]);
    }
    function get_group_members_json() {
        $this->require_login();
        
        $g = $this->require_group(F3::get('PARAMS.id'));
        $members = F3::get('DB')->exec('SELECT gm.id member_id, coalesce(u.username,gm.invited_name) display_name,u.id user_id,u.email,gm.invited_token
        FROM `group_member` gm LEFT OUTER JOIN `user` u ON gm.user_id=u.id
        WHERE gm.group_id=?', [$g->id]);
        foreach($members as &$d) if($d['invited_token'])$d['link'] = base_url().'/join/'.$d['invited_token'];
        render_json(["success"=>true, "members" => $members]);
    }
    
    function create_expense() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.id'));
        
        $db = F3::get('DB');
        $db->begin();
        
        $exp = dbm('expense');
        $exp->group_id = $g->id;
        $exp->type = "Expense";
        $exp->who_paid = intval($_POST['who_paid']);
        $exp->amount = floatval($_POST['amount']);
        $exp->description = $_POST['description'];
        $exp->date = sql_now();
        if (!$exp->who_paid || $exp->amount == 0) { render_json(["success"=>false,'msg'=>'missing data']); return; }
        if (!is_array($_POST['split'])) { render_json(["success"=>false,'msg'=>'select at least one who must pay']); return; }
        $exp->save();
        
        
        foreach($_POST['split'] as $member_id) {
            $esu = dbm('expense_split_user');
            $esu->expense_id = $exp->id;
            $esu->member_id = $member_id;
            $esu->save();
        }
        $db->commit();
        
        render_json(["success"=>true, 'id'=>$exp->id]);
        
    }
    function create_group_member() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.id'));
        
        $gm = dbm('group_member');
        $gm->group_id = $g->id;
        $gm->user_id = NULL;
        $gm->invited_name = $_POST['display_name'];
        $gm->invited_token = guid();
        $gm->created_at = sql_now();
        $gm->save();
        
        render_json(["success"=>true, 'link' => base_url().'/join/'.$gm->invited_token]);
        
    }
    function kick_group_member() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.id'));
        
        $gm = dbm('group_member');
        $gm->load(["group_id=? AND id=?", $g->id, F3::get('PARAMS.member')]);
        if ($gm->dry()) { render_json(["success"=>false,"msg"=>"member not found"]); return; }
        if ($gm->user_id == NULL) { render_json(["success"=>false,"msg"=>"nothing to do"]); return; }
        if (F3::get('DB')->exec('SELECT COUNT(*) c FROM group_member WHERE group_id=? AND NOT(user_id IS NULL)', [$g->id])[0]['c'] < 2) {
            render_json(["success"=>false,"msg"=>"last man standing"]); return;
        }
        $user = dbm('user');
        $user->load(["id=?", $gm->user_id]);
        
        $gm->user_id = NULL;
        $gm->invited_name = $user->username." (removed)";
        $gm->invited_token = guid();
        $gm->joined_at = NULL;
        $gm->save();
        
        render_json(["success"=>true, ]);
        
    }
    
    
    function delete_expense() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.group'));
        
        $exp = dbm('expense');
        $exp->load(['group_id=? AND id=?', $g->id, F3::get('PARAMS.expense')]);
        if($exp->dry()){ render_json(["success"=>false ]); return; }
        $exp->erase();
        
        render_json(["success"=>true ]);
        
    }
    
    
    function require_invite_token($token) {
        $gm = dbm("group_member");
        $gm->load(["invited_token=?", $token]);
        if ($gm->dry()) F3::error(404);
        F3::set('group_member', $gm);
        
        $g = dbm("group");
        $g->load(["id=?", $gm->group_id]);
        F3::set('group', $g);
    }
    function ask_join_group() {
        $this->require_login();
        $this->require_invite_token(F3::get("PARAMS.token"));
        
        $this->render_layout('askjoin.htm');
    }
    function join_group() {
        $this->require_login();
        $this->require_invite_token(F3::get("PARAMS.token"));
        
        $gm = F3::get('group_member');
        $gm->invited_token = NULL;
        $gm->invited_name = NULL;
        $gm->user_id = $_SESSION['userid'];
        $gm->joined_at = sql_now();
        $gm->save();
        F3::reroute('/group/'.$gm->group_id);
    }
}



$f3->run();


