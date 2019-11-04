<?php

/**
 * Track and display key metrics for the program, to measure growth and effectiveness.
 */

namespace WordPressDotOrg\FiveForTheFuture\Stats;
use WordPressDotOrg\FiveForTheFuture\{ XProfile };

defined( 'WPINC' ) || die();


/*
 register a custom post type, each post represents a monthly snapshot of all the stats we want to track
	 public       => false
	 show_in_rest => true
	 supports     => array( 'custom-fields' )


 schedule a cron job to run once a month, and gather all the data from #37
	 each datum is stored as a postmeta entry for the snapshot post. e.g.:

	 wp_posts
	       ID              143
	       post_date       { determined automatically when inserted }
	       post_content    {empty}
	       post_title      {empty}
	       post_status     publish

	  wp_postmeta
	       post_id     meta_key                                meta_value
	       --------------------------------------------------------------
	       143         5ftf_stat_total_hours_contributed       1985
	       143         5ftf_stat_total_contributors            153
	       ...


	 functions from xprofile.php would be used whenever possible
		 e.g. total hours would would
			 fetch all pledge IDs ( numberposts => -1, fields => ID )
			 call get_aggregate_contributor_data_for_pledge(), add hours to running total
			 save total in post meta field

	when #38 wants to generate a report
		it'd query for all stat posts ( numberposts => -1 )
		then loop through each one and produce whatever data structure the visualization framework wants

*/
