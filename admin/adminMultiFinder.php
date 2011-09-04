<?php
/*
    Copyright (C) 2004-2010 Kestas J. Kuliukas

	This file is part of webDiplomacy.

    webDiplomacy is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    webDiplomacy is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with webDiplomacy.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('IN_CODE') or die('This script can not be run by itself.');

/**
 * This script gives mods and admins the data needed to find multi-accounters, by parsing
 * wD_AccessLog, as well as other techniques.
 *
 * It uses a lot of resources for large data-sets, which the people who use it should be
 * aware of.
 *
 * @package Admin
 */

adminMultiCheck::form();

if ( isset($_REQUEST['aUserID']) and $_REQUEST['aUserID'] )
{
	try
	{
		if ( isset($_REQUEST['bUserIDs']) and $_REQUEST['bUserIDs'] )
		{
			$m = new adminMultiCheck($_REQUEST['aUserID'], $_REQUEST['bUserIDs']);
		}
		else
		{
			$m = new adminMultiCheck($_REQUEST['aUserID']);
		}

		$m->printCheckSummary();

		$m->aLogsDataCollect();

		if ( !is_array($m->bUserIDs) )
			$m->findbUserIDs();

		if ( ! $m->bUserIDs )
		{
			print '<p>This account has no links with other accounts</p>';
		}
		else
		{
			if( isset($_REQUEST['showHistory']) )
			{
				$m->timeData();
			}
			else
			{
				foreach($m->bUserIDs as $bUserID)
				{
					try {
						$bUser = new User($bUserID);
					} catch(Exception $e) {
						print '<p><strong>'.$bUserID.' is an invalid user ID.</strong></p>';
						continue;
					}

					$m->compare($bUser);
				}
			}
		}
	}
	catch(Exception $e)
	{
		print '<p><strong>Error:</strong> '.$e->getMessage().'</p>';
	}
}

/**
 * This class manages a certain user's often used multi-account comparison data, as well
 * as a list of users which are being compared to. $aUser is the first user, $bUser is the
 * second, of which there will likely be several
 *
 * @package Admin
 */
class adminMultiCheck
{
	/**
	 * Print a form for selecting which user to check, and which users to check against
	 */
	public static function form()
	{
		print '<form method="get" action="admincp.php#viewMultiFinder">';

		print '<p><strong>User ID:</strong><br />The user ID to check<br />
				<input type="text" name="aUserID" value="" length="50" /></p>';

		print '<p><strong>*Check against user IDs:</strong><br />An optional comma-separated list
				of user-IDs to compare the above user ID to. If this is not specified the user ID
				above will be checked against accounts which have matching IP/cookie-code data.<br />
				<input type="text" name="bUserIDs" value="" length="300" /></p>';

		print '<p><strong>Show complete history for the user and links found:</strong>
				<input type="checkbox" name="showHistory" /><br />
				With this checked the complete access log data for all the matching accounts will be displayed,
				instead of displaying the list of linked accounts.
				This makes it easy to check whether people are accessing the site during the same time periods,
				and gives a more detailed picture of what is happening.
				</p>';

		print '<p><strong>Links between user accounts have to share active games:</strong>
				<input type="checkbox" name="activeLinks" /><br />
				With this checked links between users will be ignored if they aren\'t currently playing in
				the same games. This helps ensure that the data being checked is relevant and cuts out the
				clutter.
				</p>';

		print '<input type="submit" name="Submit" class="form-submit" value="Check" />
				</form>';
	}

	private function printTimeDataRow($row, $lastRow=false)
	{
		static $alternate;
		if ( !isset($alternate) ) $alternate = false;
		$alternate = !$alternate;

		print '<tr class="replyalternate'.(2-$alternate).' replyborder'.(2-$alternate).'">';

		foreach($row as $name=>$part)
		{
			print '<td>';

			if ( $name == 'userID')
			{
				if ( $part == $this->aUserID )
					print '<strong>'.$part.'</strong>';
				else
					print $part;

				continue;
			}

			if ( $lastRow )
			{
				if ( $name == 'lastRequest' )
				{
					$timeComparison = '('.libTime::remainingText($lastRow['lastRequest'],$part).' earlier)';

					if ( ( $lastRow['lastRequest'] - $part ) < 15*60 )
						print '<span class="Austria">'.$timeComparison.'</span>';
					elseif ( ( $lastRow['lastRequest'] - $part ) < 30*60 )
						print '<span class="Turkey">'.$timeComparison.'</span>';
					elseif ( ( $lastRow['lastRequest'] - $part ) < 45*60 )
						print '<span class="Italy">'.$timeComparison.'</span>';
					else
						print $timeComparison;
				}
				else
				{
					if ( $part == $lastRow[$name] )
						print '<span class="Austria">'.$part.'</span>';
					else
						print $part;
				}
			}
			else
			{
				if ( $name == 'lastRequest' )
					print libTime::text($part);
				else
					print $part;
			}

			print '</td>';
		}

		print '</tr>';
	}

	public function timeData()
	{
		global $DB;

		$userIDs = $this->bUserIDs;
		array_push($userIDs, $this->aUserID);

		print '<p>Outputting access log history for the users being checked</p>';

		if ( isset($_REQUEST['activeLinks']) and count($this->aLogsData['activeGameIDs']) )
		{
			$tabl = $DB->sql_tabl(
				"SELECT UNIX_TIMESTAMP(a.lastRequest) as lastRequest, a.userID, u.username,
					a.hits, a.cookieCode, INET_NTOA(a.ip) as ip, HEX(a.userAgent) as userAgent
				FROM wD_AccessLog a
 				INNER JOIN wD_Users u ON ( u.id = a.userID )
				INNER JOIN wD_Members m ON ( a.userID = m.userID )
				WHERE
					m.gameID IN (".implode(',', $this->aLogsData['activeGameIDs']).")
					AND a.userID IN ( ".implode(',',$userIDs) .")
				ORDER BY a.lastRequest DESC"
			);
		}
		else
		{
			$tabl = $DB->sql_tabl(
				"SELECT UNIX_TIMESTAMP(a.lastRequest) as lastRequest, a.userID, u.username,
					a.hits, a.cookieCode, INET_NTOA(a.ip) as ip, HEX(a.userAgent) as userAgent
				FROM wD_AccessLog a INNER JOIN wD_Users u ON ( u.id = a.userID )
				WHERE a.userID IN ( ".implode(',',$userIDs) .")
				ORDER BY a.lastRequest DESC"
			);
		}


		print '<table>';

		$headers = array('Time', 'User ID', 'Username', 'Pages', 'Cookie code', 'IP', 'User agent');
		foreach($headers as &$header) $header='<strong>'.$header.'<strong>';
		$this->printTimeDataRow($headers);

		$gap = 0;
		$lastRow = false;
		$lastUserID = 0;

		while( $row = $DB->tabl_hash($tabl) )
		{
			if ( $row['userID'] != $lastUserID )
			{
				$lastUserID = $row['userID'];

				if ( $gap > 0 )
				{
					//$this->printTimeDataRow(array($gap.' rows from the same user</tr>'));
					$this->printTimeDataRow($headers);
					$this->printTimeDataRow($lastRow);
				}

				$this->printTimeDataRow($row, $lastRow);

				$gap = 0;
			}
			else
			{
				$gap++;
			}

			$lastRow = $row;
		}

		if ( $gap > 0 )
		{
			print '<tr><td>'.$gap.' rows from the same user.</td></tr>';
			$this->printTimeDataRow($lastRow);
		}

		print '</table>';
	}

	/**
	 * The user ID being checked
	 * @var int
	 */
	public $aUserID;

	/**
	 * The user being checked
	 * @var User
	 */
	public $aUser;

	/**
	 * Data from the user being checked which is used repeatedly
	 * @var mixed[]
	 */
	public $aLogsData=array();

	/**
	 * The user IDs which the aUser is being checked against
	 * @var int[]
	 */
	public $bUserIDs;

	/**
	 * Set the class up to check a certain user
	 *
	 * @param int $aUserID The ID of the user being checked
	 * @param int[] $bUserIDs=false [Optional]The IDs to check against; possible suspects will be selected if none are given
	 */
	public function __construct($aUserID, $bUserIDs=false)
	{
		$this->aUserID = (int)$aUserID;

		$this->aUser = new User($this->aUserID);

		if( $bUserIDs !== false )
		{
			$arr = explode(',',$bUserIDs);
			$this->bUserIDs = array();

			foreach($arr as $bUserID)
			{
				if ( $aUserID == $bUserID ) continue;

				$this->bUserIDs[] = (int)$bUserID;
			}
		}
	}

	/**
	 * If no bUserIDs were given on construction some users to be checked against
	 * have to be found. This is done by finding cookie-code and IP matches, resulting
	 * in bUserIDs being set.
	 */
	public function findbUserIDs()
	{
		global $DB;

		if ( isset($_REQUEST['activeLinks']) and count($this->aLogsData['activeGameIDs']) )
		{
			$tabl = $DB->sql_tabl(
				"SELECT DISTINCT a.userID
				FROM wD_AccessLog a
				INNER JOIN wD_Members m ON ( a.userID = m.userID )
				WHERE
					m.gameID IN (".implode(',', $this->aLogsData['activeGameIDs']).")
					AND NOT a.userID = ".$this->aUserID."
					AND (
						a.cookieCode IN ( ".implode(',',$this->aLogsData['cookieCodes'])." )
						OR a.ip IN ( ".implode(',',$this->aLogsData['IPs'])." )
					)"
				);
		}
		else
		{
			$tabl = $DB->sql_tabl(
				"SELECT DISTINCT userID
				FROM wD_AccessLog
				WHERE NOT userID = ".$this->aUserID."
					AND (
						cookieCode IN ( ".implode(',',$this->aLogsData['cookieCodes'])." )
						OR ip IN ( ".implode(',',$this->aLogsData['IPs'])." )
					)"
				);
		}


		$arr=array();
		while( list($bUserID) = $DB->tabl_row($tabl) )
		{
			$arr[] = $bUserID;
		}

		$this->bUserIDs = $arr;
	}

	/**
	 * Print a summary of the check which is about to be performed
	 */
	public function printCheckSummary()
	{
		print '<p>Checking <a href="profile.php?userID='.$this->aUserID.'">'.$this->aUser->username.'</a>'.
			' ('.$this->aUser->points.' '.libHTML::points().')
			(#'.$this->aUserID.')</p>';

		if( is_array($this->bUserIDs) )
		{
			print '<p>Checking against specified user accounts: '.implode(', ',$this->bUserIDs).'.</p>';
		}
		else
		{
			print '<p>Checking against IP/cookie-code linked users.</p>';
		}
	}

	/**
	 * Run a SQL query and return the first column as an array. If $tally is given
	 * the second column is stored too (and is used for tallys for the first column in practice).
	 *
	 * @param string $sql The 1/2 column SQL query which will return the list
	 * @param array $tally=false If provided the 2nd column will be stored in this array, indexed by the first.
	 *
	 * @return array The generated list from the first column
	 */
	private static function sql_list($sql, &$tally=false)
	{
		global $DB;

		$tabl = $DB->sql_tabl($sql);

		if ( $tally === false )
		{
			$list = array();
			while( list($row) = $DB->tabl_row($tabl) )
			{
				$list[] = $row;
			}
		}
		else
		{
			$list = array();
			while( list($row, $count) = $DB->tabl_row($tabl) )
			{
				if ( is_array($tally) ) $tally[$row] = $count;

				$list[] = $row;
			}
		}

		return $list;
	}

	/**
	 * Collect data aboue aUser from the AccessLogs which is useful for checking
	 * for multi-accounts, so that it can be saved in aLogsData and re-used for
	 * each bUserID checked against.
	 *
	 * If enough data isn't found in the AccessLogs this will throw an exception.
	 */
	public function aLogsDataCollect()
	{
		global $DB;

		$this->aLogsData = array();

		$this->aLogsData['IPs'] = self::sql_list(
			"SELECT ip, COUNT(ip)
			FROM wD_AccessLog
			WHERE userID = ".$this->aUserID."
			GROUP BY ip"
		);

		$this->aLogsData['cookieCodes'] = self::sql_list(
			"SELECT DISTINCT cookieCode
			FROM wD_AccessLog
			WHERE userID = ".$this->aUserID." AND cookieCode > 1"
		);

		$this->aLogsData['userAgents'] = self::sql_list(
			"SELECT DISTINCT HEX(userAgent)
			FROM wD_AccessLog
			WHERE userID = ".$this->aUserID
		);

		$this->aLogsData['fullGameIDs'] = self::sql_list(
			"SELECT DISTINCT gameID
			FROM wD_Members
			WHERE userID = ".$this->aUserID
		);

		// Up until now all aLogsData arrays must be populated
		foreach($this->aLogsData as $name=>$data)
		{
			if ( ! is_array($data) or ! count($data) )
			{
				throw new Exception($name.' does not have enough data; this account cannot be checked.');
			}
		}

		list($this->aLogsData['total']) = $DB->sql_row(
				"SELECT COUNT(ip) FROM wD_AccessLog WHERE userID = ".$this->aUserID
			);

		$this->aLogsData['activeGameIDs'] = self::sql_list(
			"SELECT DISTINCT m.gameID
			FROM wD_Members m INNER JOIN wD_Games g ON ( g.id = m.gameID )
			WHERE m.userID = ".$this->aUserID." AND NOT g.phase = 'Finished'"
		);
	}

	/**
	 * Check a single data-type from aUser with the same data-type from one of the bUsers.
	 * Various details are printed which help the viewer decide if there is a significant similariry.
	 * If the final tallys and counts are provided the main ratio will be determined from the tallys rather
	 * than the individual match types.
	 *
	 * @param string $name The name of the data-type being compared
	 * @param string[] $matches An array containing each of the individual match types in both aUser and bUser
	 * @param int $matchCount The number of individual match types which were in aUser and bUser (e.g. 3 shared distinct IPs)
	 * @param int $totalMatchCount The total number of individual match types possible (e.g. 5 distinct IPs)
	 * @param array $scale An array of ratio-cutoff points, indexed by the CSS class to set if the ratio is between the indexed cutoff
	 * @param array $aTally=false The tally for the number of occurrances of each match in $matches for aUser
	 * @param int $aTotalCount=false The total number of records in the AccessLog for aUser, to convert the tally to a percentage
	 * @param array $bTally=false The tally for the number of occurrances of each match in $matches for bUser
	 * @param int $bTotalCount=false The total number of records in the AccessLog for bUser, to convert the tally to a percentage
	 */
	private static function printDataComparison($name, array $matches, $matchCount, $totalMatchCount,
				array $scale, $aTally=false, $aTotalCount=false, $bTally=false, $bTotalCount=false)
	{
		if ( is_array($aTally) and is_array($bTally) )
		{
			/*
			 * Use the tallys to find the ratio. If each match type contains the same percentage of occurrances
			 * for each user it contributes towards a higher ratio more than if one is very large, and the other is
			 * small.
			 * i.e. Differences is respective match tallys will bring the ratio down, identical respective match
			 * tallys bring the ratio up.
			 */

			$ratio = 0.0;

			foreach($matches as $match)
			{
				$ratio += min(
						$aTally[$match]/$aTotalCount,
						$bTally[$match]/$bTotalCount
					);
			}

			$ratioText = $matchCount.'/'.$totalMatchCount.' ('.round($ratio*100).'%)';
		}
		else
		{
			// The ratio is simply the number of individual data-types found in both users divided by the total number
			// of individual data-types in aUser (i.e. the amount of overlap / the maximum possible overlap)
			$ratio = $matchCount/( $totalMatchCount==0 ? 1 : $totalMatchCount );
			$ratioText = $matchCount.'/'.$totalMatchCount;
		}

		// Determine the color based on the ratio which was just found
		$color = false;
		foreach($scale as $subColor=>$subLimit)
		{
			if ( $ratio > $subLimit )
				$color = $subColor;
			else
				break;
		}

		if ( $color )
		{
			$ratioText = '<span class="'.$color.'">'.$ratioText.'</span>';
		}

		print '<li><strong>'.$name.':</strong> '.$ratioText.'<br />';

		// Display the matches; in the case of tallys used provide a tallied match list, otherwise a plain match list
		if ( is_array($aTally) and is_array($bTally) )
		{
			$newMatches = array();
			foreach($matches as $match)
			{
				$newMatches[] = $match.' ('.round(100*$aTally[$match]/$aTotalCount).'%-'.round(100*$bTally[$match]/$bTotalCount).'%)';
			}
			print implode(', ', $newMatches);
		}
		else
		{
			print implode(', ', $matches);
		}

		print '</li>';
	}

	private function compareIPData($bUserID, $bUserTotal)
	{
		$aUserTotal = $this->aLogsData['total'];
		$aUserData = $this->aLogsData['IPs'];

		$bTally=array();
		$matches = self::sql_list(
			"SELECT ip, COUNT(ip)
			FROM wD_AccessLog
			WHERE userID = ".$bUserID." AND ip IN ( ".implode(',',$aUserData)." )
			GROUP BY ip", $bTally
		);
		if( count($matches) )
		{
			$aTally=array();
			self::sql_list(
				"SELECT ip, COUNT(ip)
				FROM wD_AccessLog
				WHERE userID = ".$this->aUserID." AND ip IN ( ".implode(',',$matches)." )
				GROUP BY ip", $aTally
			);
			self::printDataComparison('IPs', $matches, count($matches), count($aUserData),
					array('Italy'=>0.1,'Turkey'=>0.2,'Austria'=>0.3), $aTally, $aUserTotal, $bTally, $bUserTotal);
		}
	}

	private function compareCookieCodeData($bUserID, $bUserTotal)
	{
		$aUserTotal = $this->aLogsData['total'];
		$aUserData = $this->aLogsData['cookieCodes'];

		$bTally=array();
		$matches = self::sql_list(
			"SELECT cookieCode, COUNT(cookieCode)
			FROM wD_AccessLog
			WHERE userID = ".$bUserID." AND cookieCode IN ( ".implode(',',$aUserData)." )
			GROUP BY cookieCode", $bTally
		);
		if( count($matches) )
		{
			$aTally=array();
			self::sql_list(
				"SELECT cookieCode, COUNT(cookieCode)
				FROM wD_AccessLog
				WHERE userID = ".$this->aUserID." AND cookieCode IN ( ".implode(',',$matches)." )
				GROUP BY cookieCode", $aTally
			);
			self::printDataComparison('CookieCode', $matches, count($matches), count($aUserData),
					array('Italy'=>0.1,'Turkey'=>0.2,'Austria'=>0.3), $aTally, $aUserTotal, $bTally, $bUserTotal);
		}
	}

	private function compareUserAgentData($bUserID, $bUserTotal)
	{
		$aUserTotal = $this->aLogsData['total'];
		$aUserData = $this->aLogsData['userAgents'];

		$bTally=array();
		$matches = self::sql_list(
			"SELECT HEX(userAgent), COUNT(userAgent)
			FROM wD_AccessLog
			WHERE userID = ".$bUserID." AND
				( ".Database::packArray("UNHEX('",$aUserData, "') = userAgent", " OR ")." )
			GROUP BY userAgent", $bTally
		);
		if( count($matches) )
		{
			$aTally=array();
			self::sql_list(
				"SELECT HEX(userAgent), COUNT(userAgent)
				FROM wD_AccessLog
				WHERE userID = ".$this->aUserID." AND
					( ".Database::packArray("UNHEX('",$matches, "') = userAgent", " OR ")." )
				GROUP BY userAgent", $aTally
			);
			self::printDataComparison('UserAgent', $matches, count($matches), count($aUserData),
					array('Italy'=>2/3,'Turkey'=>3/4,'Austria'=>7/8), $aTally, $aUserTotal, $bTally, $bUserTotal);
		}
	}

	private function compareGames($name, $bUserID, $gameIDs)
	{
		$matches = self::sql_list(
			"SELECT DISTINCT gameID
			FROM wD_Members
			WHERE userID = ".$bUserID." AND gameID IN ( ".implode(',',$gameIDs)." )"
		);

		$linkMatches = array();
		foreach($matches as $match)
			$linkMatches[] = '<a href="board.php?gameID='.$match.'" class="light">'.$match.'</a>';
		$matches = $linkMatches;
		unset($linkMatches);

		self::printDataComparison($name, $matches, count($matches), count($gameIDs),
				array('Italy'=>1/4,'Turkey'=>1/2,'Austria'=>2/3) );
	}

	/**
	 * Compares this class' aUser with one of its bUsers, and the data returned from the comparison
	 * makes it easy to tell if the two users are being played by the same player.
	 *
	 * @param User $bUser The user to compare aUser with
	 */
	public function compare(User $bUser)
	{
		global $DB;

		print '<ul>';
		print '<li><a href="profile.php?userID='.$bUser->id.'">'.$bUser->username.'</a> ('.$bUser->points.' '.libHTML::points().')
					(<a href="?aUserID='.$bUser->id.'#viewMultiFinder" class="light">check userID='.$bUser->id.'</a>)
				<ul>';

		list($bUserTotal) = $DB->sql_row("SELECT COUNT(ip) FROM wD_AccessLog WHERE userID = ".$bUser->id);

		$this->compareIPData($bUser->id, $bUserTotal);
		$this->compareCookieCodeData($bUser->id, $bUserTotal);
		$this->compareUserAgentData($bUser->id, $bUserTotal);

		$this->compareGames('All games', $bUser->id, $this->aLogsData['fullGameIDs']);

		if ( count($this->aLogsData['activeGameIDs']) > 0 )
			$this->compareGames('Active games', $bUser->id, $this->aLogsData['activeGameIDs']);

		print '</ul></li></ul>';
	}
}

?>