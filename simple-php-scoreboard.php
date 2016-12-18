<?php
/*
----------------------------
SIMPLE SCOREBOARD
----------------------------
*/
$settings = array(
	/*
	----------------------------
	REQUIRED PARAMETERS
	You must fill these so your scoreboard can work.
	----------------------------
	*/
	'password' => '',					//The password you want to use in order to make important changes to your scoreboard. Must be set.
	'database_host' => '',				//Your mySQL database host.
	'database_name' => '',				//Your mySQL database name.
	'database_user' => '',				//Your mySQL database user name.
	'database_password' => '',			//Your mySQL database password.
	/*
	----------------------------
	OTHER PARAMETERS
	----------------------------
	*/
	'no_submission_time' => 30,			//How long (in seconds) should a player with a given IP address wait before submitting another score?
	'enable_md5_check' => false,		//Should the scoreboard check for a md5 hash before submitting score? Leave this to TRUE to prevent cheating.
);

//Defines how a score timestamp should be displayed
function display_date($d)
{
	$diff = time()-$d;
	$ret = '';

	if($diff < 60) $ret = $diff.' seconds ago';
	if($diff >= 60 && $diff < 3600) $ret = floor($diff/60).' min ago';
	if($diff >= 3600 && $diff < 86400) $ret = floor($diff/3600).' hours ago';
	if($diff >= 86400) $ret = date('m/d/Y H:i');
	$ret = $d;
	return $ret;
}

//Checking if a password has been defined
if(!$settings['password'])
{
	echo 'You need to define a password first.';
	exit;
}


//Setting parameters from POST or GET variables.
$params = array(
	'mode' => '',
	'password' => '',
	'game' => '',
	'player' => '',
	'score' => 0,
	'md5' => '',
	'createtables' => '',
	'range' => '',
	'rank' => '',
);
foreach($params as $key => $value)
{
	if(isset($_POST[$key])) $params[$key] = $_POST[$key];
	else if(isset($_GET[$key])) $params[$key] = $_GET[$key];
}

//Initializing database
try
{
	$dbh = new PDO('mysql:host='.$settings['database_host'].';dbname='.$settings['database_name'].';charset=utf8',$settings['database_user'], $settings['database_password'],array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
}
catch (PDOException $e)
{
    echo 'Database error : ' . $e->getMessage().'<br/>';
    echo 'Please make sure that you provided the right database host, name, user and password.';
    exit;
}


switch($params['mode'])
{
	
	/*No password required*/
	case 'get_scores':
		displayScoresRange($dbh,$settings,$params);
	break;
	case 'get_rank':
		displayRankRange($dbh,$settings,$params);
	break;
	case 'get_score_rank':
		echo getScoreRank($dbh,$params['game'],$params['score']);
	break;
	case 'get_score_to_rank':
		displayScoreToRank($dbh,$settings,$params);
	break;
	case 'get_player':
		displayPlayer($dbh,$settings,$params);
	break;
	case 'get_count':
		echo getScoreCount($dbh,$params['game']);
	break;

	case 'send':
		sendMode($dbh,$settings,$params);
	break;

	/*Password required*/
	case 'new_game':
		newGameMode($dbh,$settings,$params);
	break;
	case 'random_scores':
		insertRandomScores($dbh,$settings,$params);
	break;
	case 'reset_scores':
		resetMode($dbh,$settings,$params);
	break;
	default:
		defaultMode($dbh,$settings,$params);
	break;
}

//Display all players ranked between the range given in parameters
function displayRankRange($db,$s,$p)
{
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	$game_data = $req->fetch();

	//Parsing parameters
	$range = explode('-',$p['range']);
	if(isset($range[1]))
	{
		$min_rank = max(1,min(max(0,(int) $range[0]),max(0,(int) $range[1])));
		$max_rank = max(1,max(max(0,(int) $range[0]),max(0,(int) $range[1])));
	}
	else $min_rank = $max_rank = (int) $range[0];

	$nb_rank = $max_rank - $min_rank + 1;
	$min_rank--;

	$current_rank = $min_rank+1;
	$display_rank = false;
	$score_rank = -1;

	//Get scores, if a same player submitted multiple score, only get the best one
	$req = $db->prepare("SELECT G1.player,G1.date,G1.score FROM simple_scoreboard_scores AS G1
	JOIN (SELECT player, ".($game_data['lower_better'] ? 'min' : 'max')."(score) as bestscore FROM simple_scoreboard_scores WHERE game = ? group by player ) AS G2
	ON G2.player = G1.player and G2.bestscore = G1.score
	ORDER BY G1.score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", G1.date ASC, G1.player LIMIT $min_rank,$nb_rank");
	$req->execute(array($p['game']));
	while($data = $req->fetch())
	{
		//If this is the first score we display, we retrieve both the display rank, but also the current rank if we would take submission time into account		
		if($display_rank == false)
		{
			$display_rank = getScoreRank($db,$p['game'],$data['score']);
			$current_rank = getScoreRank($db,$p['game'],$data['score'],$data['date']);
			$score_rank = $data['score'];
		}
		//If the score we display is different than the previous one, the display rank is set to be the current rank
		if($score_rank != $data['score'])
		{
			$display_rank = $current_rank;
			$score_rank = $data['score'];
		}
		//Display score
		displayScore($display_rank,$data['player'],$data['score'],$data['date']);	
		$current_rank++;
	}
}
//Display all players who scored between the range given in parameters
function displayScoresRange($db,$s,$p)
{
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	$game_data = $req->fetch();

	$range = explode('-',$p['range']);
	if(isset($range[1]))
	{
		$min_score = min(max(0,(int) $range[0]),max(0,(int) $range[1]));
		$max_score = max(max(0,(int) $range[0]),max(0,(int) $range[1]));
	}
	else $min_score = $max_score = (int) $range[0];
	
	$req = $db->prepare("SELECT COUNT(DISTINCT player) AS startrank FROM simple_scoreboard_scores WHERE game = ? AND score ".($game_data['lower_better'] ? '< '.$min_score : '> '.$max_score));
	$req->execute(array($p['game']));
	$data = $req->fetch();
	$current_rank = $data['startrank']+1;
	$display_rank = $current_rank;
	$score_rank = -1;

	//Get scores, if a same player submitted multiple score, only get the best one
	$req = $db->prepare("SELECT G1.player,G1.date,G1.score FROM simple_scoreboard_scores AS G1
	JOIN (SELECT player, ".($game_data['lower_better'] ? 'min' : 'max')."(score) as bestscore FROM simple_scoreboard_scores WHERE game = ? group by player ) AS G2
	ON G2.player = G1.player and G2.bestscore = G1.score
	WHERE score >= $min_score AND score <= $max_score 
	ORDER BY G1.score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", G1.date ASC, G1.player");
	$req->execute(array($p['game']));

	while($data = $req->fetch())
	{
		if($score_rank != $data['score'])
		{
			$display_rank = $current_rank;
			$score_rank = $data['score'];
		}
		displayScore($display_rank,$data['player'],$data['score'],$data['date']);	
		$current_rank++;
	}
}

//Get a score's rank.
function getScoreRank($db,$game,$score,$date = null)
{
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($game));
	$game_data = $req->fetch();

	$score = (int) $score;
	if(is_null($date))
	{
		$req = $db->prepare("SELECT COUNT(DISTINCT player) AS rank FROM simple_scoreboard_scores WHERE game = ? AND score ".($game_data['lower_better'] ? '<' : '>')." ? ORDER BY score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", date ASC");
		$req->execute(array($game,$score));
	}
	else
	{
		$req = $db->prepare("SELECT COUNT(DISTINCT player) AS rank FROM simple_scoreboard_scores WHERE game = ? AND (score ".($game_data['lower_better'] ? '<' : '>')." ? OR (score = ? AND date < ?)) ORDER BY score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", date ASC");
		$req->execute(array($game,$score,$score,$date));
	}
	$data = $req->fetch();
	return $data['rank']+1;
}

//Get score(s) needed to achieve a certain rank, which may be given in percentage
function displayScoreToRank($db,$s,$p)
{
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	$game_data = $req->fetch();

	$ranks = explode('-',$p['rank']);
	$score_count = getScoreCount($db,$p['game']);
	foreach ($ranks as $rank) 
	{
		if(substr($rank, -1) == '%')
		{
			$percent = (int) rtrim($rank, "%");
			$rank = (int) ((100-$percent)*$score_count/100) + 1;
		}
		else $rank = (int) $rank;
		$rank = max(1,$rank)-1;

		$req = $db->prepare("SELECT G1.player,G1.date,G1.score FROM simple_scoreboard_scores AS G1
		JOIN (SELECT player, ".($game_data['lower_better'] ? 'min' : 'max')."(score) as bestscore FROM simple_scoreboard_scores WHERE game = ? group by player ) AS G2
		ON G2.player = G1.player and G2.bestscore = G1.score
		ORDER BY G1.score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", G1.date ASC, G1.player LIMIT $rank,1");
		$req->execute(array($p['game']));
		$data = $req->fetch();

		echo $data['score'].';';
	}
}

//Get number of submitted scores
function getScoreCount($db,$game)
{
	$req = $db->prepare("SELECT COUNT(DISTINCT player) AS count FROM simple_scoreboard_scores WHERE game = ?");
	$req->execute(array($game));
	$data = $req->fetch();
	return isset($data['count']) ? $data['count'] : 0;
}

//Get a player's score and rank. If the range parameter is specified and contains a number, also shows scores near the selected player
function displayPlayer($db,$s,$p)
{
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	$game_data = $req->fetch();

	$p['range'] = (int) $p['range'];
	if($p['range'] <= 0) $p['range'] = 1;

	$req = $db->prepare("SELECT * FROM simple_scoreboard_scores WHERE game = ? AND player = ? ORDER BY score ".($game_data['lower_better'] ? 'ASC' : 'DESC').", date ASC LIMIT 1");
	$req->execute(array($p['game'],$p['player']));
	if($data = $req->fetch()) //The player was found. Fetching its rank.
	{
		//We get the player's rank taking submission time into account
		$real_rank = getScoreRank($db,$p['game'],$data['score'],$data['date']);
		$start = (int) max(0,$real_rank - 1 - ($p['range']-1)/2);
		$p['range'] = ($start+1).'-'.($start+$p['range']);
		displayRankRange($db,$s,$p);
	}
	else //The player was not found. Display the first scores given by range.
	{
		$start = 0;
		$p['range'] = ($start+1).'-'.($start+$p['range']);
		displayRankRange($db,$s,$p);
	}
}

//Display score in CSV format
function displayScore($rank,$player,$score,$date)
{
	echo $rank.';'.$player.';'.$score.';'.display_date($date).';<br/>';
}


//Send a new score
function sendMode($db,$s,$p)
{
	$dont_submit = false;
	$p['score'] = (int) $p['score'];

	//Checking if game exists, if so, retrieve salt
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	if($data = $req->fetch())
	{
		$salt = $data['salt'];
	}
	else
	{
		echo 'WRONG_GAME';
		exit;
	}

	//Checking if a score has been submitted by the same IP address less than $settings['no_submission_time'] seconds ago, to prevent flooding.
	$req = $db->prepare('SELECT * FROM simple_scoreboard_scores WHERE ip = ? AND date > ?');
	$req->execute(array($_SERVER['REMOTE_ADDR'],time() - $s['no_submission_time']));
	if($data2 = $req->fetch())
	{
		echo 'SCORE_SUBMITTED_BEFORE';
		exit;
	}

	//Check if this player already submitted the same score for this game before, if so, don't submit.
	$req = $db->prepare('SELECT * FROM simple_scoreboard_scores WHERE game = ? AND player = ? AND SCORE = ?');
	$req->execute(array($p['game'],$p['player'],$p['score']));
	if($data2 = $req->fetch())
	{
		$dont_submit = true;
	}

	//Checking if score is valid
	if($p['score'] <= 0)
	{
		echo 'SCORE_NEGATIVE';
		exit;
	}
	if($p['score'] < $data['min'])
	{
		echo 'SCORE_TOO_LOW';
		exit;
	}
	if($data['max'] && $p['score'] > $data['max'])
	{
		echo 'SCORE_TOO_HIGH';
		exit;
	}
	if($data['score_interval'] > 1 && ($p['score']%$data['score_interval']) != 0)
	{
		echo 'SCORE_INTERVAL';
		exit;
	}

	//Changing player's name so there are no confusions
	$p['player'] = strtoupper(remove_accents($p['player']));

	//Checking player's name length
	if(strlen($p['player']) < 3 || strlen($p['player']) > 30)
	{
		echo 'PLAYER_LENGTH';
		exit;
	}
	//Checking if player's name includes a semicolon
	if(strpos($p['player'],';') !== FALSE)
	{
		echo 'PLAYER_SEMICOLON';
		exit;
	}

	//echo md5($p['game'].$p['player'].$p['score'].$salt);
	//Checking md5 hash
	if($s['enable_md5_check'] && md5($p['game'].$p['player'].$p['score'].$salt) != $p['md5'])
	{
		echo 'WRONG_MD5';
		exit;
	}

	//We retrieve current player's rank

	//Everything seems fine, so the score will be submitted.
	if(!$dont_submit)
	{
		$req = $db->prepare('INSERT INTO simple_scoreboard_scores (game,player,score,date,ip) VALUES (?,?,?,?,?)');
		$req->execute(array($p['game'],$p['player'],$p['score'],time(),$_SERVER['REMOTE_ADDR']));
	}
	echo 'OK';

	//Then, we have to retrieve the submitted score's rank, the new player's rank (it may be different, if he submitted a score which was not his personal best) 10 scores around player, and the top 10
}

//Inserts 100 random scores on game provided within parameters, without checking. For testing purposes.
function insertRandomScores($db,$s,$p,$count = 100)
{
	//Checking password.
	if($p['password'] != $s['password'])
	{
		echo 'The password you provided doesn\'t match with the one defined in this PHP file.';
		exit;
	}

	//Checking if game exists
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	if(!$data = $req->fetch())
	{
		echo 'The game passed in parameters does not exist.';
		exit;
	}
	echo 'Selected game is : '.$p['game'].'<br/>';
	for($i = 0; $i < $count; $i++)
	{
		$player = generateRandomString(3);
		$score = mt_rand(5,8000);
		$req = $db->prepare('INSERT INTO simple_scoreboard_scores (game,player,score,date,ip) VALUES (?,?,?,?,?)');
		$req->execute(array($p['game'],$player,$score,time(),$_SERVER['REMOTE_ADDR']));
		echo 'Inserted score : '.$score.' by player : '.$player.'<br/>';
	}
}

//Resets score tables for the game provided within parameters
function resetMode($db,$s,$p)
{
	//Checking password.
	if($p['password'] != $s['password'])
	{
		echo 'The password you provided doesn\'t match with the one defined in this PHP file.';
		exit;
	}

	$req = $db->prepare('DELETE FROM simple_scoreboard_scores WHERE game = ?');
	$req->execute(array($p['game']));

	echo 'All scores for the game '.$p['game'].' were erased.<br/>';
	echo '<a href="?password='.$p['password'].'">Back to home page</a>';
}

//New game mode. Create a new game for scores to be submitted.
function newGameMode($db,$s,$p)
{
	//Checking password.
	if($p['password'] != $s['password'])
	{
		echo 'The password you provided doesn\'t match with the one defined in this PHP file.';
		exit;
	}

	//Checking game name.
	if(trim($p['game']) == '')
	{
		echo 'Please provide a game name.';
		exit;
	}
	if(strlen($p['game']) > 20)
	{
		echo 'Your game\'s name is too long (max. 20 characters).';
		exit;
	}

	//Checking if the game exists. If so, retreiving salt.
	$salt = '';
	$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
	$req->execute(array($p['game']));
	if ($data = $req->fetch())
	{
		$salt = $data['salt'];
		if(isset($_POST['set_min'])) //"Set parameters" button was clicked
		{
			$req = $db->prepare('UPDATE simple_scoreboard_games SET min = ?, max = ?, score_interval = ?, lower_better = ? WHERE name = ?');
			$req->execute(array($_POST['set_min'],$_POST['set_max'],$_POST['set_score_interval'],isset($_POST['set_lower_better']),$p['game']));
			$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
			$req->execute(array($p['game']));
			$data = $req->fetch();
		}

	}
	else
	{
		$salt = generateRandomString();
		$req = $db->prepare('INSERT INTO simple_scoreboard_games (name,salt) VALUES (?,?)');
		$req->execute(array($p['game'],$salt));
		$req = $db->prepare('SELECT * FROM simple_scoreboard_games WHERE name = ?');
		$req->execute(array($p['game']));
		$data = $req->fetch();

	}

	//Displaying info.
	echo 'The game <strong>'.$p['game'].'</strong> now exists in your database.<br/><br/>';
	echo 'You can set the following parameters for your game : <br/><form action="" method="post">
	<input type="hidden" name="mode" value="new_game"/>
	<input type="hidden" name="password" value="'.$p['password'].'"/>
	<input type="hidden" name="game" value="'.$p['game'].'"/>
	<ul>
		<li>The <strong>minimal</strong> possible score is : <input type="number" min="0" value="'.$data['min'].'" name="set_min"/></li>
		<li>The <strong>maximal</strong> possible score is : <input type="number" min="0" value="'.$data['max'].'" name="set_max"/> (leave to 0 for no maximum)</li>
		<li>The scores must be <strong>divisible</strong> by : <input type="number" min="1" value="'.max(1,$data['score_interval']).'" name="set_score_interval"/> (for instance, setting this to 5 will only allow scores ending with a 0 or 5 digit)</li>
		<li>By default, the best scores are the highest, tick this so the best scores are the lowest (e.g. for speedruns) <input type="checkbox" name="set_lower_better" '.($data['lower_better'] ? 'checked' : '').'/></li>
	</ul>
	<input type="submit" value="Set parameters"/>
	</form>';
	$base_url = explode('?','http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . "{$_SERVER['HTTP_HOST']}/{$_SERVER['REQUEST_URI']}")[0];
	$params = '?mode=send&game='.$p['game'].'&player='.'<strong><em>player_name</em></strong>'.'&score='.'<strong><em>submitted_score</em></strong>'.'&md5='.'<strong><em>md5_hash</em></strong>';
	

	echo '<br/><br/>';
	echo 'To <strong>get scoreboards</strong> for this game, try the following :<br/>
	<ul>
		<li>
			'.$base_url.'?game='.$p['game'].'&<strong>mode=get_rank&range=A-B</strong> will output all scores which are ranked between A and B, included.<br/>
			Examples :<br/>
			<em>'.$base_url.'?game='.$p['game'].'&mode=get_rank&range=1-20</em> will output the 20 best scores.<br/>
			<em>'.$base_url.'?game='.$p['game'].'&mode=get_rank&range=51-100</em> will output all scores from the 51st to the 100th.<br/>
			<br/>
		</li>
		<li>
			'.$base_url.'?game='.$p['game'].'&<strong>mode=get_player&player=P&range=R</strong> will output player P\'s best score, as well as other scores around him<br/>
			Examples :<br/>
			<em>'.$base_url.'?game='.$p['game'].'&mode=get_player&player=john</em> will output john\'s best score.<br/>
			<em>'.$base_url.'?game='.$p['game'].'&mode=get_player&player=john&range=10</em> will output 10 scores around john\'s best score (if john is 7th, this will display all scores from the 2nd to the 11th).<br/>
			<br/>
		</li>
		<li>
			'.$base_url.'?game='.$p['game'].'&<strong>mode=get_scores&range=A-B</strong> will output all scores between A and B.<br/>
			Example :<br/>
			<em>'.$base_url.'?game='.$p['game'].'&mode=get_scores&range=1000-2000</em> will output all scores between 1000 and 2000 points.<br/>
			<br/>
		</li>
		<li>
			'.$base_url.'?game='.$p['game'].'&<strong>mode=get_score_rank&score=S</strong> will output the rank a player would reach if he scored S right now.<br/>
		</li>
	</ul>
	';

	echo 'When applicable, outputs is created in <strong>CSV format</strong> and will look like this : 
	<strong>&lt;rank&gt;;&lt;player_name&gt;;&lt;score&gt;;&lt;date (MM/DD/YYYY)&gt;;&lt;rank&gt;...</strong>';


	echo '<br/><br/>';
	
	echo 'To <strong>submit a score</strong> for this game, call the following url :<br/>';
	echo $base_url.$params.'<br/>';
	echo 'where : <br/>
	<ul>
		<li><em>submitted_score</em> is the score the player just did,</li>
		<li><em>player_name</em> is the player\'s name,</li>
		<li><em>md5_hash</em> is a MD5 hash generated with the following string : 
		<span style="color:red">'.$p['game'].'</span><span style="color:green">player_name</span><span style="color:blue">submitted_score</span><span style="color:brown">'.$salt.'</span></li>
	</ul>
	You may also use POST parameters instead of GET.';
}

//Default mode, when no parameter is passed. Create score tables if they don't exist, then displays help files.
function defaultMode($db,$s,$p)
{
	//Checking password.
	if($p['password'] != $s['password'])
	{
		echo 'The password you provided doesn\'t match with the one defined in this PHP file.';
		exit;
	}

	//Initial table creation
	if($p['createtables'])
	{
		$req = $db->query("DROP TABLE IF EXISTS simple_scoreboard_games");
		$req = $db->query("DROP TABLE IF EXISTS simple_scoreboard_scores");
		$table ="CREATE table simple_scoreboard_games(
		     id INT( 11 ) AUTO_INCREMENT PRIMARY KEY,
		     name VARCHAR( 20 ) NOT NULL, 
		     salt VARCHAR( 20 ) NOT NULL,
		     lower_better TINYINT( 1 ) NOT NULL,
		     min INT( 11 ) NOT NULL,
		     max INT( 11 ) NOT NULL,
		     score_interval INT( 11 ) NOT NULL)" ;
		$db->exec($table);
		$table ="CREATE table simple_scoreboard_scores(
		     id INT( 11 ) AUTO_INCREMENT PRIMARY KEY,
		     game VARCHAR( 20 ) NOT NULL, 
		     player VARCHAR( 30 ) NOT NULL, 
		     score INT( 11 ) NOT NULL,
		     date INT( 11 ) NOT NULL,
		     ip VARCHAR( 45 ) NOT NULL)" ;
		$db->exec($table);
	}
	$tables_count = tableExists($db, 'simple_scoreboard_games') + tableExists($db, 'simple_scoreboard_scores');

	//No database tables exist, they have to be created
	if($tables_count == 0)
	{
		echo 'Thanks for using simple-scoreboard. First, the tables which will store your games\' scores on the database will be created.<br/>';
		echo 'They will be called "simple_scoreboard_games" and "simple_scoreboard_scores". <a href="?password='.$p['password'].'&createtables=1">Click here to start creating these tables.</a>';
	}
	//One of the two tables exists, that's weird
	if($tables_count == 1)
	{
		echo 'Thanks for using simple-scoreboard. First, the tables which will store your games\' scores on the database will be created.<br/>';
		echo 'They will be called "simple_scoreboard_games" and "simple_scoreboard_scores". As one of them already exist, it has to be erased in order to continue. <a href="?password='.$p['password'].'&createtables=1">Click here to start creating these tables.</a>';
	}
	//The two tables exist.
	if($tables_count == 2)
	{
		$columns_scores = getColumns($db,'simple_scoreboard_scores');
		$columns_games = getColumns($db,'simple_scoreboard_games');
		//The columns aren't the right ones, that's weird. Note : the columns type and other parameters are not checked.
		if($columns_scores != array('id','game','player','score','date','ip') || $columns_games != array('id','name','salt','lower_better','min','max','score_interval'))
		{
			echo 'Oops. Looks like the right tables exist in your database, but they don\'t have the right columns.<br/>';
			echo 'Your highscore tables need to be erase and re-created. <a href="?password='.$p['password'].'&createtables=1">Click here to do so.</a>';
		}
		//Everything seem to work fine. Displaying help. 
		else 
		{
			echo 'Welcome to simple-scoreboard home page !';
		}
	}
}


/*---
HELPERS
---*/

//Check if a table exists within db
function tableExists($db, $table) 
{
    try 
    {
        $result = $db->query("SELECT 1 FROM $table LIMIT 1");
    } 
    catch (Exception $e) 
    {
        return FALSE;
    }
    return $result !== FALSE;
}

//List all columns in a table's db
function getColumns($db,$table)
{
	$rs = $db->query("SELECT * FROM $table LIMIT 0");
	$columns = array();
	for ($i = 0; $i < $rs->columnCount(); $i++) 
	{
	    $col = $rs->getColumnMeta($i);
	    $columns[] = $col['name'];
	}
	return $columns;
}

//Generate random letter string
function generateRandomString($length = 8) {
    $characters = 'abcdefghijklmnopqrstuvwxyz';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

//Remove accents form a string
function remove_accents($str, $charset='utf-8')
{
    $str = htmlentities($str, ENT_NOQUOTES, $charset);
   
    $str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
    $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
    $str = preg_replace('#&[^;]+;#', '_', $str); 
    
    return $str;
}
?>