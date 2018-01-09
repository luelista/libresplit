<?php

// Kickstart the framework
$f3=require('lib/base.php');

if ((float)PCRE_VERSION<7.9)
        trigger_error('PCRE version is out of date');

// Load configuration
$f3->config('app.ini');
$f3->config('.htconfig.ini');
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
function guid2() {
    return sprintf('%04X%04X%04X%04X%04X%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
}
function dbm($model) {
    return new \DB\SQL\Mapper(F3::get('DB'), $model);
}
function render_json($json) {
    header("Content-Type: application/json", true);
    echo json_encode($json);
}
function get_mailer() {
    $mailer = new \SMTP( F3::get('SMTP.host') , F3::get('SMTP.port') , F3::get('SMTP.security') , 
            F3::get('SMTP.user') , F3::get('SMTP.password') );
    $mailer->set('X-Mailer', 'libresplit');
    $mailer->set('Date', date('r'));
    $mailer->set('Message-ID', '<'.guid().'@'.$_SERVER['SERVER_NAME'].'>');
    $mailer->set('From', F3::get('SMTP.from_address'));
    $mailer->set('Errors-To', F3::get('SMTP.support_address'));
    $mailer->set('Reply-To', F3::get('SMTP.support_address'));
    return $mailer;
}
class LibreSplit {
    function __construct() {
        $tokenAuth = $_SERVER["HTTP_AUTHORIZATION"] ?: $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?: $_SERVER["REDIRECT_REDIRECT_HTTP_AUTHORIZATION"];
        if (strpos($tokenAuth, "Bearer ") === 0) {
          $permatoken = dbm('login_token');
          $permatoken->load(['token=?', substr($tokenAuth, 7)]);
          if (!$permatoken->dry()) {
            $user = dbm('user');
            $user->load(['id=?', $permatoken->user_id]);
            $this->login_user($user);
          } else {
            header("HTTP/1.1 403 Invalid Token");
            return;
          }
        } else {
          session_start();
          if(!$_SESSION["csrf"]) $_SESSION["csrf"]=guid();
          $this->try_tokencookie_login();
        }
    }
    private function api_result($values) {
        if (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') === 0 || $_GET['format'] == 'json') {
            header('Content-Type: application/json; charset=utf8');
            foreach($values as $x)  {
                $v = F3::get($x);
                if ($v instanceof \DB\SQL\Mapper) $v = $v->cast();
                $dict[$x] = $v;
            }
            echo json_encode( $dict );
            exit();
        }
    }
    private function render_layout($content) {
        F3::set('content', $content);
        echo \Template::instance()->render('layout.htm');
    }
    private function render_errmes($message) {
        F3::set('ERROR.status', 'Oops, something went wrong!');
        F3::set('ERROR.text', $message);
        $this->render_framework_errmes();
    }
    function render_framework_errmes() {
        $this->api_result(['ERROR.status', 'ERROR.text']);
        F3::set('content', 'errmes.htm');
        echo \Template::instance()->render('layout.htm');
    }
    function index() {
        if ($_SESSION['userid']) {
            F3::reroute('/groups');
        } else {
            $this->render_layout('frontpage.htm');
        }
    }
    
    private function papertrail($g, $action, $object_type, $str_repr) {
        $pt = dbm('papertrail');
        $pt->actor_user_id = $_SESSION["userid"];
        $pt->group_id = $g == NULL ? NULL : $g['id'];
        $pt->action = $action;
        $pt->object_type = $object_type;
        $pt->repr = $str_repr;
        $pt->save();
        $noti = F3::get('DB')->exec('SELECT u.id,username,email
            FROM user u
                INNER JOIN group_member gm ON u.id=gm.user_id
            WHERE gm.notifications > 0 AND gm.group_id = ?',
            [ $g['id'] ]);
        $mailer = get_mailer();
        $mailer->set('Subject', "[LibreSplit $g[name]] $_SESSION[username] ${action}s $object_type");
        $mail_body = "L i b r e S p l i t   N o t i f i c a t i o n\nGroup: ".$g["name"]."\n*$_SESSION[username] ${action}s $object_type*\n".
            "> " . str_replace("\n", "\n> ", $str_repr) .
            "\n\n\nYou may use the following link to access your account:\n";
        foreach($noti as $user) {
            if ($user['id'] != $_SESSION['userid'] && $user['email']) {
                $mailer->set('To', "$user[username] <$user[email]>");
                $link = $this->make_login_link($user['email']);
                $mailer->send($mail_body . $link . "\n\n");
            }
        }
    }
    private function make_login_link($email) {
        if (!$email) throw new Exception("missing email address in make_login_link");
        $timestamp = time();
        $token = base64_encode(sha1( F3::get('app_secret') . $timestamp . $email , true));
        return base_url().'/s?'.base_convert($timestamp,10,36).'&'.urlencode($token).'&'.urlencode($email);
    }

    private function try_tokencookie_login() {
        if ((!$_SESSION['userid']) && isset($_COOKIE["libresplitlogin"]) && strlen($_COOKIE["libresplitlogin"]) == 32) {
            $permatoken = dbm('login_token');
            $permatoken->load(['token=?', $_COOKIE["libresplitlogin"]]);
            if (!$permatoken->dry()) {
                $user = dbm('user');
                $user->load(['id=?', $permatoken->user_id]);
                $this->login_user($user);
                //$this->login_redirect();
                setcookie('libresplitlogin', $permatoken->token, time()+62208000, '/');
                return TRUE;
            } else {
                setcookie('libresplitlogin', 'INVALID', time()-9001);
            }
        }
        return FALSE;
    }
    function login() {
        if ($_SESSION['userid']) {
            $this->login_redirect();
            return;
        }
        if ($_POST["login_email"]) {
            $link = $this->make_login_link($_POST['login_email']);
            $mailer = get_mailer();
            $mailer->set('Subject', "LibreSplit Login / Registration");
            $mailer->set('To', $_POST['login_email']);
            $ok = $mailer->send("Hi,\n\nTo login to LibreSplit or to create your LibreSplit \naccount, please click the link below:\n\n$link\n\n");
            if ($ok == TRUE) {
                $this->render_layout('loginmail.htm');
                return;
            } else
                F3::set('login_error', 'An error has occured while sending a confirmation message to your email address. Please try again later.');
        }
        if ($_GET["identity"]) {
            $openid=new \Web\OpenID;
            $openid->identity=$_GET["identity"];
            if($_GET['id']=='select')$openid->localidentity="http://specs.openid.net/auth/2.0/identifier_select";
            $openid->return_to=base_url().'/verified';
            if ($openid->auth() === FALSE) {
                F3::set('login_error', "This is not a valid openid");
            } else {
                return;
            }
        }
        $this->render_layout('frontpage.htm');
    }
    function mail_verified() {
        if ($_SESSION['userid']) {
            $this->login_redirect();
            return;
        }
        list($timestamp, $token, $email) = explode("&", $_SERVER["QUERY_STRING"]);
        $timestamp = intval(base_convert($timestamp,36,10));
        $token = urldecode($token);
        $email = urldecode($email);
        F3::set('REQUEST.login_email', $email);
        if ($timestamp+2*3600 < time()) {
            http_response_code(400);
            F3::set('login_error', "This confirmation link has expired. Please request a new link by clicking 'login' below.");
            $this->api_result(['login_error']);
            $this->render_layout('frontpage.htm');
            return;
        }
        $correct_token = base64_encode(sha1( F3::get('app_secret') . $timestamp . $email , true));
        if ($token !== $correct_token) {
            http_response_code(400);
            F3::set('login_error', "There seems to be a problem with your confirmation link. Please try copying the complete link from your mail program and paste it into the address bar of your browser.");
            $this->api_result(['login_error']);
            $this->render_layout('frontpage.htm');
            return;
        }
        
        $user = dbm('user');
        $user->load(['email = ?', $email ]);
        
        if (strtotime($user->last_login_at) >= $timestamp) {
            http_response_code(400);
            F3::set('login_error', "This confirmation link has already been used. Please request a new link by clicking 'login' below.");
            $this->api_result(['login_error']);
            $this->render_layout('frontpage.htm');
            return;
        }
        
        if ($user->dry()) { // first login by this email
            $user->openid=NULL;
            $user->email=$email;
            $user->created_at=sql_now();
            $user->save();
        }
        
        $this->login_user($user);
        $this->set_login_token($user);
        F3::set('email', $email); F3::set('username', $user->username);
        $this->api_result(['email','username','access_token']);
        $this->login_redirect();
    }
    private function set_login_token($user) {
        $permatoken = dbm('login_token');
        $permatoken->user_id = $user->id;
        $permatoken->token = guid2();
        $permatoken->save();
        setcookie('libresplitlogin', $permatoken->token, time()+62208000, '/');
        $_SESSION["logintoken"] = $permatoken->token;
        F3::set('access_token', $permatoken->token);
    }
    function openid_verified() {
        if ($_SESSION['userid']) {
            $this->login_redirect();
            return;
        }
        $openid=new \Web\OpenID;
        //var_dump($openid->response());
        if (!$openid->verified()) {
                F3::set('login_error', "Some error occured while logging in");
                
            $this->render_layout('frontpage.htm');
            return;
        }
        
        $user = dbm('user');
        $user->load(['openid = ?', $openid->identity]);
        
        if ($user->dry()) { // first login by this openid
            $user->openid=$openid->identity;
            $user->created_at=sql_now();
        }
        
        $_SESSION["oid"]=$openid->response();
        
        $this->login_user($user);
        $this->set_login_token($user);
        $this->login_redirect();
    }
    private function login_user($user) {
        $user->last_login_at = sql_now();
        $user->save();
        $_SESSION["userid"] = $user->id;
        $_SESSION["username"] = $user->username;
        $_SESSION["email"] = $user->email;
    }
    private function login_redirect() {
        if (isset($_SESSION['loginreturn'])) {
            $path = $_SESSION['loginreturn'];
            unset($_SESSION['loginreturn']);
            F3::reroute($path);
        } elseif (!$_SESSION['email']) {
            F3::reroute('/profile?msg=welcome');
        } else {
            $ug = dbm('group_member');
            if (1 === $ug->count(['user_id = ?', $_SESSION['userid']])) {
                $ug->load(['user_id = ?', $_SESSION['userid']]);
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
        F3::get('DB')->exec('DELETE FROM login_token WHERE token = ?', [ $_SESSION["logintoken"] ]);
        unset($_SESSION["logintoken"]);
        setcookie('libresplitlogin', '', 0);
        F3::reroute('/');
    }
    private function require_login($require_email=TRUE) {
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
    private function require_group($guid, $is_readonly_method=FALSE) {
        $g = dbm('group');
        $g->load(['id=?', $guid]);
        if ($g->dry()) F3::error(404);
        
        $gm = dbm('group_member');
        $gm->load(['user_id=? AND group_id=?', $_SESSION['userid'], $g->id]);
        if ($gm->dry()) {
            if ($is_readonly_method && F3::exists('GET.t') && $g->readonly_token == F3::get('GET.t')) {
                F3::set('readonly', true);
            } else {
                $this->require_login();
                F3::error(404);
            }
        } else {
            F3::set('readonly', false);
        }
        
        F3::set('group', $g);
        F3::set('membership', $gm);
        
        return $g;
    }
    function show_profile() {
        $this->require_login(FALSE);
        $user = dbm('user');
        $user->load(['id=?', $_SESSION['userid']]);
        if ($user->dry()) F3::error(404);
        F3::set('profile', $user);

        $this->api_result(['profile']);
        $this->render_layout('profile.htm');
    }
    function show_app_token() {
        $this->require_login(TRUE);
        $user = dbm('user');
        $user->load(['id=?', $_SESSION['userid']]);
        if ($user->dry()) F3::error(404);
        F3::set('profile', $user);
        F3::set('login_link', base64_encode($this->make_login_link($user->get('email'))));

        $this->render_layout('app_token.htm');
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
        $this->papertrail(NULL, "update", "profile", "$user[username] $user[email]");
        if (!$user->email)
            F3::reroute('/profile?msg=email');
        else {
            F3::set('location', $_SESSION['loginreturn'] ?: '/groups');
            unset($_SESSION['loginreturn']);
            $this->render_layout('profilenext.htm');
        }
    }
    function show_groups() {
        $this->require_login();
        $groups = F3::get('DB')->exec('SELECT g.* , GROUP_CONCAT(COALESCE(u.username,gm.invited_name) SEPARATOR ", ") members
            FROM group_member me
                INNER JOIN `group` g ON me.group_id=g.id
                LEFT OUTER JOIN `group_member` gm ON gm.group_id=g.id
                LEFT OUTER JOIN `user` u ON u.id=gm.user_id
                
            WHERE me.user_id = ?
                GROUP BY g.id
                ', [ $_SESSION['userid'] ]);
        
        F3::set('groups', $groups);
        $this->api_result(['groups']);
        $this->render_layout('groups.htm');
    }
    function create_group() {
        $this->require_login();
        
        $g = dbm('group');
        $g->id = guid();
        $g->readonly_token = guid();
        $g->name = $_POST['name'];
        $g->color = sprintf('%06X', mt_rand(0, 0xFFFFFF));
        $g->comment = '';
        $g->save();
        $this->papertrail($g, "add", "group", "");
        
        $gm = dbm('group_member');
        $gm->group_id = $g->id;
        $gm->user_id = $_SESSION['userid'];
        $gm->created_at = sql_now();
        $gm->joined_at = sql_now();
        $gm->notifications = 1;
        $gm->save();
        $this->papertrail($g, "add", "group_member", "group creator is now member");
        
        F3::reroute('/group/' . $g->id);
    }
    function show_group_expenses() {
        if (!F3::exists('GET.t')) $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.id'), TRUE);
        
        F3::set('group', $g);
        
        $e = F3::get('DB')->exec('SELECT e.*,
        COALESCE(paid_u.username,paid_gm.invited_name) who_paid_name,
         GROUP_CONCAT(concat(COALESCE(u.username,gm.invited_name), " (€",format(uu.amount/100,2),")") separator ", ") split_members
        FROM expense e LEFT OUTER JOIN expense_split_user uu ON e.id=uu.expense_id
        LEFT OUTER JOIN group_member gm ON uu.member_id=gm.id
        LEFT OUTER JOIN user u ON gm.user_id=u.id
        
        LEFT OUTER JOIN group_member paid_gm ON e.who_paid=paid_gm.id
        LEFT OUTER JOIN user paid_u ON paid_gm.user_id=paid_u.id
        
        WHERE e.group_id = ?
        GROUP BY e.id
        ORDER BY e.date DESC,e.created_at DESC', [$g->id]);
        F3::set('expenses', $e);
        $this->api_result(['group', 'expenses']);
        $this->render_layout('group.htm');
    }
    function delete_group() {
        $this->require_login();
        
		$g = $this->require_group(F3::get('PARAMS.id'));
		$ok = F3::get('DB')->exec('UPDATE group_member SET user_id = NULL WHERE group_id = ?', [ $g->id ]);
		var_dump($ok);
	}
    function update_group() {
        $this->require_login();
        
        $g = $this->require_group(F3::get('PARAMS.id'));
        if (isset($_POST['notifications'])) {
            $membership = F3::get('membership');
            $membership->notifications = ($_POST['notifications'] == "true") ? 1 : 0;
            $membership->save();
        }
        
        $pt="";
        foreach ([ 'name', 'color', 'comment' ] as $field)
            if (isset($_POST[$field])) {
                $g[$field] = $_POST[$field];
                $pt.="$field=".$_POST[$field]."\n";
            }
        $g->save();
        if ($pt) $this->papertrail($g, "update", "group", $pt);
        
        render_json(["success" => true]);
    }
    function show_topay() {
        $g = $this->require_group(F3::get('PARAMS.id'), TRUE);
        
        $debts = F3::get('DB')->exec('SELECT sum(esu.amount) debt, esu.member_id debtor, ee.who_paid creditor
         FROM expense ee 
                left outer join expense_split_user esu on ee.id=esu.expense_id
         WHERE ee.group_id = ?
                AND esu.member_id <> ee.who_paid
         GROUP BY esu.member_id,ee.who_paid', [$g->id]);
        
        render_json(["success" => true, "to_pay" => $debts]);
    }
    private function get_group_members($g) {
        $members = F3::get('DB')->exec('SELECT gm.id member_id, coalesce(u.username,gm.invited_name) display_name,u.id user_id,u.email,gm.invited_token
        FROM `group_member` gm LEFT OUTER JOIN `user` u ON gm.user_id=u.id
        WHERE gm.group_id=? ORDER BY coalesce(u.username,gm.invited_name)', [$g->id]);
        foreach($members as &$d) if($d['invited_token'])$d['link'] = base_url().'/join/'.$d['invited_token'];
        return $members;
    }
    function get_group_members_json() {
        $g = $this->require_group(F3::get('PARAMS.id'), TRUE);
        $members = $this->get_group_members($g);
        render_json(["success"=>true, "members" => $members]);
    }
    private function find_member($members, $id) {
        foreach($members as $m)
            if ($m['member_id'] == $id)
                return $m;
    }
    private function store_expense_from_post_data($g, $exp) {
        $members = $this->get_group_members($g);

        $db = F3::get('DB');
        $db->begin();

        $factor = 1;
        if ($_POST['amount_float']) $factor=100;

        $exp->who_paid = intval($_POST['who_paid']);
        if (isset($_POST['amount'])) $exp->amount = round($_POST['amount']*$factor,0);
        $exp->description = $_POST['description'];
        if (isset($_POST['date'])) $exp->date = $_POST['date'];
        if (isset($_POST['type'])) $exp->type = $_POST['type'];
        if (!$exp->who_paid || $exp->amount == 0) throw new InvalidArgumentException('missing data');
        if (!is_array($_POST['split']))
            throw new InvalidArgumentException("select at least one who must pay");
        $is_new = $exp->dry();
        if (!$is_new) $db->exec("DELETE FROM expense_split_user WHERE expense_id = ?", [ $exp->id ]);
        $exp->updated_at = sql_now();
        $exp->save();

        $split = $_POST['split'];
        $sum = 0;
        $split_names = [];
        foreach($split as $split_member_id => $split_amount) {
            $member_data = $this->find_member($members, $split_member_id);
            if (!$member_data) throw new InvalidArgumentException("invalid member id $split_member_id");
            $esu = dbm('expense_split_user');
            $esu->expense_id = $exp->id;
            $esu->member_id = $split_member_id;
            $esu->payer_member_id = intval($_POST['who_paid']);
            $esu->amount = round($split_amount*$factor,0);
            $sum += $esu->amount;
            $esu->save();
            $split_names[] = sprintf('%s (%0.02f)', $member_data['display_name'], $esu->amount/100);
        }
        if ($sum != $exp->amount) {
            $db->rollback(); throw new InvalidArgumentException("sum of split amounts $sum must match expense amount {$exp->amount}");
        }
        $db->commit();
        $this->papertrail($g, $is_new?"add":"update", "expense", sprintf("Description: %s\nAmount %0.02f paid by %s on %s\nSplit amongst %s",
            $exp->description, $exp->amount/100, $this->find_member($members, $exp->who_paid)['display_name'], $exp->date, implode(", ", $split_names)));
    }
    function create_expense() {
        try{
            $this->require_login();
            $g = $this->require_group(F3::get('PARAMS.id'));
            
            $exp = dbm('expense');
            $exp->group_id = $g->id;
            $exp->type = "Expense";
            $exp->date = sql_now();
            $this->store_expense_from_post_data($g, $exp);
            
            render_json(["success"=>true, 'id'=>$exp->id]);
        }catch(Exception $ex) {
            render_json(["success"=>false,'msg'=>$ex->getMessage()]);
        }
    }
    function update_expense() {
        try{
            $this->require_login();
            $g = $this->require_group(F3::get('PARAMS.group'));
            
            $exp = dbm('expense');
            $exp->load(['group_id=? AND id=?', $g->id, F3::get('PARAMS.expense')]);
            $this->store_expense_from_post_data($g, $exp);
            
            switch (\Web::instance()->acceptable(['text/html','application/json'])) {
            case 'application/json':  render_json(["success"=>true, 'id'=>$exp->id]); break;
            case 'text/html':  F3::reroute('/group/'.$g->id.'#'.$exp->id); break;
            }
            
        }catch(Exception $ex) {
            switch (\Web::instance()->acceptable(['text/html','application/json'])) {
            case 'application/json':  render_json(["success"=>false, 'msg'=>$ex->getMessage()]); break;
            case 'text/html':  $this->render_errmes($ex->getMessage()); break;
            }
        }
    }
    function edit_expense() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.group'));
        $ex = dbm('expense');
        $ex->load(['id=? and group_id=?', F3::get('PARAMS.expense'), $g->id]);
        
        if ($ex->dry()) { render_json(["success"=>false,]); return; }
        
        $splits = F3::get('DB')->exec('SELECT member_id,
            COALESCE(u.username,gm.invited_name) display_name, uu.amount
        FROM expense_split_user uu 
        LEFT OUTER JOIN group_member gm ON uu.member_id=gm.id
        LEFT OUTER JOIN user u ON gm.user_id=u.id
        WHERE uu.expense_id = ?
        ', [$ex->id]);
        
        switch (\Web::instance()->acceptable(['text/html','application/json'])) {
        case 'application/json':
            render_json(["success"=>"true", "expense" => $ex->cast(), "splits"=> $splits]);
            break;
        case 'text/html':
            F3::set('expense', $ex);
            F3::set('splits', $splits);
            F3::set('members', $this->get_group_members($g));
            $this->render_layout('editexpense.htm');
            break;
        }
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
        $this->papertrail($g, "add", "group_member", "invited name={$gm->invited_name}");
        
        render_json(["success"=>true, 'link' => base_url().'/join/'.$gm->invited_token]);
        
    }
    function update_group_member() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.id'));
        
        $gm = dbm('group_member');
        $gm->load(["group_id=? AND id=?", $g->id, F3::get('PARAMS.member')]);
        if ($gm->dry()) { render_json(["success"=>false,"msg"=>"member not found"]); return; }
        if ($gm->user_id != NULL) { render_json(["success"=>false,"msg"=>"can't update joined member"]); return; }
        $gm->invited_name = $_POST["display_name"];
        $gm->invited_token = guid();
        $gm->save();
        $this->papertrail($g, "update", "group_member", "member renamed: {$gm->invited_name}");
        
        render_json(["success"=>true, ]);
        
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
        $this->papertrail($g, "update", "group_member", "member left: {$gm->invited_name}");
        
        render_json(["success"=>true, ]);
        
    }
    
    
    function delete_expense() {
        $this->require_login();
        $g = $this->require_group(F3::get('PARAMS.group'));
        
        $exp = dbm('expense');
        $exp->load(['group_id=? AND id=?', $g->id, F3::get('PARAMS.expense')]);
        if($exp->dry()){ render_json(["success"=>false ]); return; }
        $exp->erase();
        $this->papertrail($g, "delete", "expense", "{$exp->type}  {$exp->description}  {$exp->amount}  {$exp->who_paid}  {$exp->date}");
        
        
        render_json(["success"=>true ]);
        
    }
    
    
    private function require_invite_token($token) {
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
        $this->papertrail($g, "update", "group_member", "member joined");
        
        F3::reroute('/group/'.$gm->group_id);
    }
}



$f3->run();


