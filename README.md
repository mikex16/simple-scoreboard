<h1><b>simple-scoreboard</b></h1>

A simple PHP script to help game developers creating an online scoreboard for their games.

<h2><b>Instructions</b></h2>

1. Install/find a web hoster with PHP and Mysql Support. Latest xampp/lampp should be great to run this script.
2. Edit the "simple-scoreboard.php" and find the settings array (Line 7)
3. Fill up the required parameter such as site password and database details.
4. Open your browser and head up to "http://[URL or IP]/simple-scoreboard.php?password=[your-password]"
5. It will ask you to create the database. Just follow the instruction and you are good to go.

<h2><b>MODE</b></h2>
"http://[URL or IP]/simple-scoreboard.php?password=[your-password]&mode=[MODE]"
<h3>1.0 Mode with no password</h3>

  1.1 mode "get_scores"
  
    Display all players who scored between the range given in parameters.
    
  1.2 mode "get_rank"
  
    Display all players ranked between the range given in parameters.
    
  1.3 mode "get_score_rank"
  
    Get a score's rank.
    
  1.4 mode "get_score_to_rank"
  
	  Get score(s) needed to achieve a certain rank, which may be given in percentage
  
  1.5 mode "get_player"
  
	  Get a player's score and rank. If the range parameter is specified and contains a number, also shows scores near the selected player
  
  1.6 mode "get_count"
  
    Get number of submitted scores
  
  1.7 mode "send"
  
    Send a new score
	
<h3>2.0 Mode with password required</h3>

  2.1 mode "new_game"
	  
    New game mode. Create a new game for scores to be submitted.
	
  2.2 mode "random_scores"
	
    Inserts 100 random scores on game provided within parameters, without checking. For testing purposes.
  
  2.3 mode "reset_scores"
	
    Resets score tables for the game provided within parameters
  
<h3>3.0 Default Mode required password to proceed</h3>

  Default mode, when no parameter is passed. Create score tables if they don't exist, then displays help files.
