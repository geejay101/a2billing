#!/usr/bin/php -q
<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * This file is part of A2Billing (http://www.a2billing.net/)
 *
 * A2Billing, Commercial Open Source Telecom Billing platform,   
 * powered by Star2billing S.L. <http://www.star2billing.com/>
 * 
 * @copyright   Copyright (C) 2004-2009 - Star2billing S.L. 
 * @author      Belaid Arezqui <areski@gmail.com>
 * @license     http://www.fsf.org/licensing/licenses/agpl-3.0.html
 * @package     A2Billing
 *
 * Software License Agreement (GNU Affero General Public License)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 * 
**/

/***************************************************************************
 *            a2billing_subscription_fee.php
 *
 *  Purpose: manage the monthly services subscription
 *  Fri Feb 27 14:17:10 2007
 *  Copyright  2007  User : Areski
 *  ADD THIS SCRIPT IN A CRONTAB JOB
 *
	crontab -e
	0 6 1 * * php /usr/local/a2billing/Cronjobs/a2billing_subscription_fee.php
	
	field	 allowed values
	-----	 --------------
	minute	 		0-59
	hour		 	0-23
	day of month	1-31
	month	 		1-12 (or names, see below)
	day of week	 	0-7 (0 or 7 is Sun, or use names)
	
	The sample above will run the script every 21 of each month at 10AM

****************************************************************************/

set_time_limit(0);
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING));

include (dirname(__FILE__) . "/lib/admin.defines.php");

$verbose_level = 0;

$groupcard = 5000;

$A2B = new A2Billing();
$A2B->load_conf($agi, NULL, 0, $idconfig);

if ($A2B->config["database"]['dbtype'] == "postgres") {
	$UNIX_TIMESTAMP = "date_part('epoch',";
} else {
	$UNIX_TIMESTAMP = "UNIX_TIMESTAMP(";
}

write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[#### BATCH BEGIN ####]");

if (!$A2B->DbConnect()) {
	echo "[Cannot connect to the database]\n";
	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[Cannot connect to the database]");
	exit;
}

$instance_table = new Table();

// CHECK AMOUNT OF CARD ON WHICH APPLY THE SERVICE
//$QUERY = 'SELECT count(*) FROM cc_card LEFT JOIN cc_subscription_fee ON cc_card.id_subscription_fee=cc_subscription_fee.id WHERE cc_subscription_fee.status=1';

$QUERY = 'SELECT count(*) FROM cc_card_subscription JOIN cc_subscription_fee ON cc_card_subscription.id_subscription_fee=cc_subscription_fee.id' .
' WHERE cc_subscription_fee.status=1 AND startdate < NOW() AND (stopdate = "0000-00-00 00:00:00" OR stopdate > NOW())';

$result = $instance_table->SQLExec($A2B->DBHandle, $QUERY);
$nb_card = $result[0][0];
$nbpagemax = (ceil($nb_card / $groupcard));
if ($verbose_level >= 1)
	echo "===> NB_CARD : $nb_card - NBPAGEMAX:$nbpagemax\n";

if (!($nb_card > 0)) {
	if ($verbose_level >= 1)
		echo "[No card to run the Subscription Fee service]\n";
	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[No card to run the Subscription Feeservice]");
	exit ();
}

// CHECK THE SUBSCRIPTION SERVICES
$QUERY = 'SELECT id, label, fee, emailreport FROM cc_subscription_fee WHERE status=1 ORDER BY id ';

$result = $instance_table->SQLExec($A2B->DBHandle, $QUERY);

if ($verbose_level >= 1)
	print_r($result);

if (!is_array($result)) {
	echo "[No Recurring service to run]\n";
	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[ No Recurring service to run]");
	exit ();
}

write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[Number of card found : $nb_card]");

$oneday = 60 * 60 * 24;

$currencies_list = get_currencies($A2B->DBHandle);

// BROWSE THROUGH THE SERVICES 
foreach ($result as $myservice) {

	$totalcardperform = 0;
	$totalcredit = 0;

	$myservice_id = $myservice[0];
	$myservice_label = $myservice[1];
	$myservice_fee = $myservice[2];

	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[Subscription Fee Service No " . $myservice_id . " analyze cards on which to apply service ]");
	// BROWSE THROUGH THE CARD TO APPLY THE SUBSCRIPTION FEE SERVICE 
	for ($page = 0; $page < $nbpagemax; $page++) {

		$sql = "SELECT cc_card.id, credit, username, email, cc_card_subscription.id " .
				"FROM cc_card JOIN cc_card_subscription ON cc_card.id = cc_card_subscription.id_cc_card " .
				"WHERE id_subscription_fee='$myservice_id' AND startdate < NOW() AND (stopdate = '0000-00-00 00:00:00' OR stopdate > NOW()) " .
				"ORDER BY cc_card.id ";

		if ($A2B->config["database"]['dbtype'] == "postgres") {
			$sql .= " LIMIT $groupcard OFFSET " . $page * $groupcard;
		} else {
			$sql .= " LIMIT " . $page * $groupcard . ", $groupcard";
		}
		if ($verbose_level >= 1)
			echo "==> SELECT CARD QUERY : $sql\n";
		$result_card = $instance_table->SQLExec($A2B->DBHandle, $sql);

		foreach ($result_card as $mycard) {
			if ($verbose_level >= 1)
				print_r($mycard);
			if ($verbose_level >= 1)
				echo "------>>>  ID = " . $mycard[0] . " - CARD =" . $mycard[3] . " - BALANCE =" . $mycard[1] . " \n";

			$amount = $myservice_fee;

			if ($verbose_level >= 1)
				echo "AMOUNT TO REMOVE FROM THE CARD ->" . $amount;
			if (abs($amount) > 0) { // CHECK IF WE HAVE AN AMOUNT TO REMOVE
				//$QUERY = "UPDATE cc_card SET credit=credit-'".$myservice_fee."' WHERE id=".$mycard[0];	
				//$result = $instance_table -> SQLExec ($A2B -> DBHandle, $QUERY, 0);
				//if ($verbose_level>=1) echo "==> UPDATE CARD QUERY: 	$QUERY\n";
				
				// ADD A CHARGE
				$QUERY = "INSERT INTO cc_charge (id_cc_card, id_cc_card_subscription, chargetype, amount, description) " .
							"VALUES ('" . $mycard[0] . "', '$mycard[4]', '3', '$amount','" . $mycard[4] . ' - ' . $myservice_label . "')";
				$result_insert = $instance_table->SQLExec($A2B->DBHandle, $QUERY, 0);
				if ($verbose_level >= 1)
					echo "==> INSERT CHARGE QUERY=$QUERY\n";

				$totalcardperform++;
				$totalcredit += $myservice_fee;
			}
		}

		// Little bit of rest
		sleep(15);
	}

	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[Service finish]");

	write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[Service report : 'totalcardperform=$totalcardperform', 'totalcredit=$totalcredit']");
	if ($verbose_level >= 1)
		echo "[Service report : 'totalcardperform=$totalcardperform', 'totalcredit=$totalcredit']";

	// UPDATE THE SERVICE		
	$QUERY = "UPDATE cc_subscription_fee SET datelastrun=now(), numberofrun=numberofrun+1, totalcardperform=totalcardperform+" . $totalcardperform .
				", totalcredit = totalcredit + '$totalcredit' WHERE id=$myservice_id";
	$result = $instance_table->SQLExec($A2B->DBHandle, $QUERY, 0);
	
	if ($verbose_level >= 1)
		echo "==> SERVICE UPDATE QUERY : $QUERY\n";
	
	// SEND REPORT
	if (strlen($myservice[3]) > 0) {
		$mail_subject = "A2BILLING SUBSCRIPTION SERVICES : REPORT";
		
		$mail_content = "SUBSCRIPTION SERVICE NAME = " . $myservice[1];
		$mail_content .= "\n\nTotal card updated = " . $totalcardperform;
		$mail_content .= "\nTotal credit removed = " . $totalcredit;
		
		try {
	        $mail = new Mail(null, null, null, $mail_content, $mail_subject);
	        $mail -> send($myservice[3]);
	    } catch (A2bMailException $e) {
	    	if ($verbose_level >= 1)
	        	echo "[Sent mail failed : $e]";
	    	write_log(LOGFILE_CRONT_ALARM, basename(__FILE__) . ' line:' . __LINE__ . "[Sent mail failed : $e]");
	    }
	}

} // END FOREACH SERVICES

if ($verbose_level >= 1)
	echo "#### END SUBSCRIPTION SERVICES \n";

write_log(LOGFILE_CRONT_SUBSCRIPTIONFEE, basename(__FILE__) . ' line:' . __LINE__ . "[#### BATCH PROCESS END ####]");


