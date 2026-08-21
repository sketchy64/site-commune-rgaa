CREATE TABLE tx_sitecommunergaa_push_subscription (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    endpoint text DEFAULT '' NOT NULL,
    p256dh varchar(255) DEFAULT '' NOT NULL,
    auth varchar(255) DEFAULT '' NOT NULL,
    user_agent text DEFAULT '' NOT NULL,

    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY parent (pid)
);
