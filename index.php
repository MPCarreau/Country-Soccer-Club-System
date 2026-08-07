<?php

declare(strict_types=1);

session_start();
date_default_timezone_set('America/Toronto');



if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}


$DB = [
    'host' => getenv('DB_HOST') ?: 'wdc353.encs.concordia.ca',
    'port' => getenv('DB_PORT') ?: '3306',
    'name' => getenv('DB_NAME') ?: 'wdc353_1',
    'user' => getenv('DB_USER') ?: 'wdc353_1',
    'pass' => getenv('DB_PASS') ?: '',
];


$configPaths = [
    '/groups/w/wd_comp353_1/db_config.php',
    __DIR__ . '/db_config.php',
];

foreach ($configPaths as $configPath) {
    if (!is_readable($configPath)) {
        continue;
    }

    $fileConfig = require $configPath;
    if (is_array($fileConfig)) {
        $DB = array_merge($DB, $fileConfig);
        break;
    }
}

$APP = [
    'title' => 'Country Soccer Club System',
    'rows_per_table' => 200,
    // For a session in year Y, eligibility is based on full payment of annual membership fee of year Y-1.
    'enforce_payment_eligibility_on_assignment' => true,
];


$SAVED_REPORTS = [
    'Q8' => [
        'title' => 'Locations with FIFA-game participants',
        'sql' => <<<'SQL'
SELECT
    l.LocationID,
    l.Name AS LocationName,
    l.Address,
    l.City,
    l.Province,
    l.PostalCode,
    GROUP_CONCAT(
        DISTINCT lp.PhoneNumber
        ORDER BY lp.PhoneNumber
        SEPARATOR ', '
    ) AS PhoneNumbers,
    l.WebAddress,
    l.LocationType,
    l.MaxCapacity,
    GROUP_CONCAT(
        DISTINCT CONCAT_WS(' ', gm.FirstName, gm.LastName)
        ORDER BY gm.FirstName, gm.LastName
        SEPARATOR ', '
    ) AS GeneralManagerName,
    (
        SELECT COUNT(*)
        FROM ClubMemberLocation AS cml_minor
        JOIN ClubMember AS cm_minor
            ON cm_minor.MembershipNumber = cml_minor.MembershipNumber
        WHERE cml_minor.LocationID = l.LocationID
          AND cml_minor.EndDate IS NULL
          AND TIMESTAMPDIFF(YEAR, cm_minor.DOB, CURDATE()) < 18
    ) AS NumberOfMinorMembers,
    (
        SELECT COUNT(*)
        FROM ClubMemberLocation AS cml_major
        JOIN ClubMember AS cm_major
            ON cm_major.MembershipNumber = cml_major.MembershipNumber
        WHERE cml_major.LocationID = l.LocationID
          AND cml_major.EndDate IS NULL
          AND TIMESTAMPDIFF(YEAR, cm_major.DOB, CURDATE()) >= 18
    ) AS NumberOfMajorMembers,
    COUNT(DISTINCT p.MembershipNumber) AS NumberOfFIFAParticipants
FROM Location AS l
JOIN ClubMemberLocation AS cml
    ON cml.LocationID = l.LocationID
   AND cml.EndDate IS NULL
JOIN Participation AS p
    ON p.MembershipNumber = cml.MembershipNumber
LEFT JOIN LocationPhone AS lp
    ON lp.LocationID = l.LocationID
LEFT JOIN WorksAt AS wa
    ON wa.LocationID = l.LocationID
   AND wa.PositionID = 1
   AND wa.EndDate IS NULL
LEFT JOIN Personnel AS gm
    ON gm.PersonnelID = wa.PersonnelID
GROUP BY
    l.LocationID,
    l.Name,
    l.Address,
    l.City,
    l.Province,
    l.PostalCode,
    l.WebAddress,
    l.LocationType,
    l.MaxCapacity
HAVING COUNT(DISTINCT p.MembershipNumber) >= 2
ORDER BY
    NumberOfFIFAParticipants DESC,
    l.LocationID ASC
SQL,
    ],

    'Q9' => [
        'title' => 'Primary family members with FIFA-game participants',
        'sql' => <<<'SQL'
SELECT DISTINCT
    fm.FamilyMemberID,
    fm.FirstName AS FamilyMemberFirstName,
    fm.LastName AS FamilyMemberLastName,
    cm.MembershipNumber,
    cm.FirstName AS ClubMemberFirstName,
    cm.LastName AS ClubMemberLastName,
    cm.DOB,
    g.RelationshipType
FROM FamilyMember AS fm
JOIN Guardianship AS g
    ON g.FamilyMemberID = fm.FamilyMemberID
   AND g.IsPrimary = 1
   AND g.EndDate IS NULL
JOIN ClubMember AS cm
    ON cm.MembershipNumber = g.MembershipNumber
WHERE EXISTS (
    SELECT 1
    FROM Participation AS p
    WHERE p.MembershipNumber = cm.MembershipNumber
)
AND (
    SELECT COUNT(DISTINCT g2.MembershipNumber)
    FROM Guardianship AS g2
    WHERE g2.FamilyMemberID = fm.FamilyMemberID
      AND g2.IsPrimary = 1
      AND g2.EndDate IS NULL
      AND EXISTS (
          SELECT 1
          FROM Participation AS p2
          WHERE p2.MembershipNumber = g2.MembershipNumber
      )
) >= 2
ORDER BY
    fm.FirstName ASC,
    fm.LastName ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q10' => [
        'title' => 'Team formations for a location and period',
        'sql' => <<<'SQL'
/* Assumption: LocationID = 1, from 2026-01-01 through 2026-05-31. */
SELECT
    hc.FirstName AS HeadCoachFirstName,
    hc.LastName AS HeadCoachLastName,
    s.SessionDateTime AS SessionStartTime,
    s.Address AS SessionAddress,
    s.SessionType,
    t.TeamName,
    CASE
        WHEN s.SessionDateTime > NOW() THEN NULL
        ELSE tf.Score
    END AS Score,
    (
        SELECT COUNT(*)
        FROM FormationAssignment AS fa_count
        WHERE fa_count.FormationID = tf.FormationID
    ) AS TotalNumberOfPlayers,
    cm.FirstName AS PlayerFirstName,
    cm.LastName AS PlayerLastName,
    fa.Role
FROM TeamFormation AS tf
JOIN `Session` AS s
    ON s.SessionID = tf.SessionID
JOIN Team AS t
    ON t.TeamID = tf.TeamID
JOIN Personnel AS hc
    ON hc.PersonnelID = tf.HeadCoachID
LEFT JOIN FormationAssignment AS fa
    ON fa.FormationID = tf.FormationID
LEFT JOIN ClubMember AS cm
    ON cm.MembershipNumber = fa.MembershipNumber
WHERE t.LocationID = 1
  AND s.SessionDateTime >= '2026-01-01 00:00:00'
  AND s.SessionDateTime < '2026-06-01 00:00:00'
ORDER BY
    s.SessionDateTime ASC,
    t.TeamName ASC,
    cm.LastName ASC,
    cm.FirstName ASC
SQL,
    ],

    'Q11' => [
        'title' => 'Members who participated in at least five FIFA games',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    COUNT(DISTINCT p.GameID) AS TotalFIFAGames,
    MIN(YEAR(fg.GameDate)) AS MinimumGameYear,
    MAX(YEAR(fg.GameDate)) AS MaximumGameYear
FROM ClubMember AS cm
JOIN Participation AS p
    ON p.MembershipNumber = cm.MembershipNumber
JOIN FIFA_Game AS fg
    ON fg.GameID = p.GameID
GROUP BY
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName
HAVING COUNT(DISTINCT p.GameID) >= 5
ORDER BY
    TotalFIFAGames DESC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q12' => [
        'title' => 'Formation summary by location and period',
        'sql' => <<<'SQL'
/* Assumption: from 2026-01-01 through 2026-05-31. */
SELECT
    l.LocationID,
    l.Name AS LocationName,
    COUNT(
        DISTINCT CASE
            WHEN s.SessionType = 'Training' THEN s.SessionID
        END
    ) AS TotalTrainingSessions,
    COALESCE(
        SUM(
            CASE
                WHEN s.SessionType = 'Training'
                 AND fa.MembershipNumber IS NOT NULL
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS TotalPlayersInTrainingSessions,
    COUNT(
        DISTINCT CASE
            WHEN s.SessionType = 'Game' THEN s.SessionID
        END
    ) AS TotalGameSessions,
    COALESCE(
        SUM(
            CASE
                WHEN s.SessionType = 'Game'
                 AND fa.MembershipNumber IS NOT NULL
                THEN 1
                ELSE 0
            END
        ),
        0
    ) AS TotalPlayersInGameSessions
FROM Location AS l
LEFT JOIN Team AS t
    ON t.LocationID = l.LocationID
LEFT JOIN TeamFormation AS tf
    ON tf.TeamID = t.TeamID
LEFT JOIN `Session` AS s
    ON s.SessionID = tf.SessionID
   AND s.SessionDateTime >= '2026-01-01 00:00:00'
   AND s.SessionDateTime < '2026-06-01 00:00:00'
LEFT JOIN FormationAssignment AS fa
    ON fa.FormationID = tf.FormationID
GROUP BY
    l.LocationID,
    l.Name
HAVING COUNT(
    DISTINCT CASE
        WHEN s.SessionType = 'Game' THEN s.SessionID
    END
) >= 4
ORDER BY
    TotalGameSessions DESC,
    l.LocationID ASC
SQL,
    ],

    'Q13' => [
        'title' => 'Active members never assigned to a formation',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) AS Age,
    cm.Phone,
    cm.Email,
    COUNT(DISTINCT p.GameID) AS NumberOfFIFAGames,
    l.Name AS CurrentLocationName
FROM ClubMember AS cm
JOIN ClubMemberLocation AS current_cml
    ON current_cml.MembershipNumber = cm.MembershipNumber
   AND current_cml.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = current_cml.LocationID
JOIN (
    SELECT
        MembershipNumber,
        MIN(StartDate) AS JoinDate
    FROM ClubMemberLocation
    GROUP BY MembershipNumber
) AS membership
    ON membership.MembershipNumber = cm.MembershipNumber
JOIN Participation AS p
    ON p.MembershipNumber = cm.MembershipNumber
WHERE NOT EXISTS (
    SELECT 1
    FROM FormationAssignment AS fa
    WHERE fa.MembershipNumber = cm.MembershipNumber
)
AND (
    (
        YEAR(membership.JoinDate) < YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE()) - 1
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(
                YEAR,
                cm.DOB,
                CONCAT(YEAR(CURDATE()) - 1, '-12-31')
            ) < 18 THEN 100
            ELSE 200
        END
    )
    OR
    (
        YEAR(membership.JoinDate) = YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE())
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
            ELSE 200
        END
    )
)
GROUP BY
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    cm.DOB,
    cm.Phone,
    cm.Email,
    l.Name
ORDER BY
    CurrentLocationName ASC,
    NumberOfFIFAGames ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q14' => [
        'title' => 'Major members who joined as minors',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    CASE
        WHEN (
            YEAR(membership.JoinDate) < YEAR(CURDATE())
            AND COALESCE(
                (
                    SELECT SUM(pay.Amount)
                    FROM Payment AS pay
                    WHERE pay.MembershipNumber = cm.MembershipNumber
                      AND pay.MembershipYear = YEAR(CURDATE()) - 1
                ),
                0
            ) >= CASE
                WHEN TIMESTAMPDIFF(
                    YEAR,
                    cm.DOB,
                    CONCAT(YEAR(CURDATE()) - 1, '-12-31')
                ) < 18 THEN 100
                ELSE 200
            END
        )
        OR (
            YEAR(membership.JoinDate) = YEAR(CURDATE())
            AND COALESCE(
                (
                    SELECT SUM(pay.Amount)
                    FROM Payment AS pay
                    WHERE pay.MembershipNumber = cm.MembershipNumber
                      AND pay.MembershipYear = YEAR(CURDATE())
                ),
                0
            ) >= CASE
                WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
                ELSE 200
            END
        )
        THEN 'Active'
        ELSE 'Inactive'
    END AS Status,
    membership.JoinDate AS DateOfJoining,
    TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) AS Age,
    cm.Phone,
    cm.Email,
    l.Name AS CurrentLocationName
FROM ClubMember AS cm
JOIN ClubMemberLocation AS current_cml
    ON current_cml.MembershipNumber = cm.MembershipNumber
   AND current_cml.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = current_cml.LocationID
JOIN (
    SELECT
        MembershipNumber,
        MIN(StartDate) AS JoinDate
    FROM ClubMemberLocation
    GROUP BY MembershipNumber
) AS membership
    ON membership.MembershipNumber = cm.MembershipNumber
WHERE TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) >= 18
  AND TIMESTAMPDIFF(YEAR, cm.DOB, membership.JoinDate) < 18
ORDER BY
    CurrentLocationName ASC,
    Age ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q15' => [
        'title' => 'Active members assigned only as Goalkeeper',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) AS Age,
    cm.Phone,
    cm.Email,
    l.Name AS CurrentLocationName,
    COUNT(DISTINCT p.GameID) AS NumberOfFIFAGames
FROM ClubMember AS cm
JOIN ClubMemberLocation AS current_cml
    ON current_cml.MembershipNumber = cm.MembershipNumber
   AND current_cml.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = current_cml.LocationID
JOIN (
    SELECT
        MembershipNumber,
        MIN(StartDate) AS JoinDate
    FROM ClubMemberLocation
    GROUP BY MembershipNumber
) AS membership
    ON membership.MembershipNumber = cm.MembershipNumber
LEFT JOIN Participation AS p
    ON p.MembershipNumber = cm.MembershipNumber
WHERE EXISTS (
    SELECT 1
    FROM FormationAssignment AS goalkeeper_assignment
    WHERE goalkeeper_assignment.MembershipNumber = cm.MembershipNumber
      AND goalkeeper_assignment.Role = 'Goalkeeper'
)
AND NOT EXISTS (
    SELECT 1
    FROM FormationAssignment AS other_assignment
    WHERE other_assignment.MembershipNumber = cm.MembershipNumber
      AND other_assignment.Role <> 'Goalkeeper'
)
AND (
    (
        YEAR(membership.JoinDate) < YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE()) - 1
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(
                YEAR,
                cm.DOB,
                CONCAT(YEAR(CURDATE()) - 1, '-12-31')
            ) < 18 THEN 100
            ELSE 200
        END
    )
    OR
    (
        YEAR(membership.JoinDate) = YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE())
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
            ELSE 200
        END
    )
)
GROUP BY
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    cm.DOB,
    cm.Phone,
    cm.Email,
    l.Name
ORDER BY
    CurrentLocationName ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q16' => [
        'title' => 'Members assigned to all five required roles',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) AS Age,
    cm.Phone,
    cm.Email,
    l.Name AS CurrentLocationName
FROM ClubMember AS cm
JOIN ClubMemberLocation AS current_cml
    ON current_cml.MembershipNumber = cm.MembershipNumber
   AND current_cml.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = current_cml.LocationID
JOIN (
    SELECT
        MembershipNumber,
        MIN(StartDate) AS JoinDate
    FROM ClubMemberLocation
    GROUP BY MembershipNumber
) AS membership
    ON membership.MembershipNumber = cm.MembershipNumber
JOIN FormationAssignment AS fa
    ON fa.MembershipNumber = cm.MembershipNumber
JOIN TeamFormation AS tf
    ON tf.FormationID = fa.FormationID
JOIN `Session` AS s
    ON s.SessionID = tf.SessionID
   AND s.SessionType = 'Game'
WHERE fa.Role IN (
    'Goalkeeper',
    'Right fullback',
    'Sweeper',
    'Defending/holding midfielder',
    'Striker'
)
AND (
    (
        YEAR(membership.JoinDate) < YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE()) - 1
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(
                YEAR,
                cm.DOB,
                CONCAT(YEAR(CURDATE()) - 1, '-12-31')
            ) < 18 THEN 100
            ELSE 200
        END
    )
    OR
    (
        YEAR(membership.JoinDate) = YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE())
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
            ELSE 200
        END
    )
)
GROUP BY
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    cm.DOB,
    cm.Phone,
    cm.Email,
    l.Name
HAVING COUNT(DISTINCT fa.Role) = 5
ORDER BY
    CurrentLocationName ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q17' => [
        'title' => 'Family members who are head coaches at the same location',
        'sql' => <<<'SQL'
/* Assumption: LocationID = 1. */
SELECT DISTINCT
    fm.FirstName,
    fm.LastName,
    fm.Phone
FROM FamilyMember AS fm
JOIN Personnel AS coach
    ON coach.SSN = fm.SSN
WHERE EXISTS (
    SELECT 1
    FROM TeamFormation AS tf
    JOIN Team AS t
        ON t.TeamID = tf.TeamID
    WHERE tf.HeadCoachID = coach.PersonnelID
      AND t.LocationID = 1
)
AND EXISTS (
    SELECT 1
    FROM Guardianship AS g
    JOIN ClubMember AS cm
        ON cm.MembershipNumber = g.MembershipNumber
    JOIN ClubMemberLocation AS current_cml
        ON current_cml.MembershipNumber = cm.MembershipNumber
       AND current_cml.EndDate IS NULL
       AND current_cml.LocationID = 1
    WHERE g.FamilyMemberID = fm.FamilyMemberID
      AND g.EndDate IS NULL
      AND (
          (
              YEAR(
                  (
                      SELECT MIN(all_cml.StartDate)
                      FROM ClubMemberLocation AS all_cml
                      WHERE all_cml.MembershipNumber = cm.MembershipNumber
                  )
              ) < YEAR(CURDATE())
              AND COALESCE(
                  (
                      SELECT SUM(pay.Amount)
                      FROM Payment AS pay
                      WHERE pay.MembershipNumber = cm.MembershipNumber
                        AND pay.MembershipYear = YEAR(CURDATE()) - 1
                  ),
                  0
              ) >= CASE
                  WHEN TIMESTAMPDIFF(
                      YEAR,
                      cm.DOB,
                      CONCAT(YEAR(CURDATE()) - 1, '-12-31')
                  ) < 18 THEN 100
                  ELSE 200
              END
          )
          OR
          (
              YEAR(
                  (
                      SELECT MIN(all_cml.StartDate)
                      FROM ClubMemberLocation AS all_cml
                      WHERE all_cml.MembershipNumber = cm.MembershipNumber
                  )
              ) = YEAR(CURDATE())
              AND COALESCE(
                  (
                      SELECT SUM(pay.Amount)
                      FROM Payment AS pay
                      WHERE pay.MembershipNumber = cm.MembershipNumber
                        AND pay.MembershipYear = YEAR(CURDATE())
                  ),
                  0
              ) >= CASE
                  WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
                  ELSE 200
              END
          )
      )
)
ORDER BY
    fm.FirstName ASC,
    fm.LastName ASC
SQL,
    ],

    'Q18' => [
        'title' => 'Active members who have never won a formation game',
        'sql' => <<<'SQL'
SELECT
    cm.MembershipNumber,
    cm.FirstName,
    cm.LastName,
    TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) AS Age,
    cm.Phone,
    cm.Email,
    l.Name AS CurrentLocationName
FROM ClubMember AS cm
JOIN ClubMemberLocation AS current_cml
    ON current_cml.MembershipNumber = cm.MembershipNumber
   AND current_cml.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = current_cml.LocationID
JOIN (
    SELECT
        MembershipNumber,
        MIN(StartDate) AS JoinDate
    FROM ClubMemberLocation
    GROUP BY MembershipNumber
) AS membership
    ON membership.MembershipNumber = cm.MembershipNumber
WHERE EXISTS (
    SELECT 1
    FROM FormationAssignment AS played_assignment
    JOIN TeamFormation AS played_formation
        ON played_formation.FormationID = played_assignment.FormationID
    JOIN `Session` AS played_session
        ON played_session.SessionID = played_formation.SessionID
    WHERE played_assignment.MembershipNumber = cm.MembershipNumber
      AND played_session.SessionType = 'Game'
)
AND NOT EXISTS (
    SELECT 1
    FROM FormationAssignment AS winning_assignment
    JOIN TeamFormation AS winning_formation
        ON winning_formation.FormationID = winning_assignment.FormationID
    JOIN `Session` AS winning_session
        ON winning_session.SessionID = winning_formation.SessionID
    JOIN TeamFormation AS opposing_formation
        ON opposing_formation.SessionID = winning_formation.SessionID
       AND opposing_formation.FormationID <> winning_formation.FormationID
    WHERE winning_assignment.MembershipNumber = cm.MembershipNumber
      AND winning_session.SessionType = 'Game'
      AND winning_formation.Score IS NOT NULL
      AND opposing_formation.Score IS NOT NULL
      AND winning_formation.Score > opposing_formation.Score
)
AND (
    (
        YEAR(membership.JoinDate) < YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE()) - 1
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(
                YEAR,
                cm.DOB,
                CONCAT(YEAR(CURDATE()) - 1, '-12-31')
            ) < 18 THEN 100
            ELSE 200
        END
    )
    OR
    (
        YEAR(membership.JoinDate) = YEAR(CURDATE())
        AND COALESCE(
            (
                SELECT SUM(pay.Amount)
                FROM Payment AS pay
                WHERE pay.MembershipNumber = cm.MembershipNumber
                  AND pay.MembershipYear = YEAR(CURDATE())
            ),
            0
        ) >= CASE
            WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18 THEN 100
            ELSE 200
        END
    )
)
ORDER BY
    CurrentLocationName ASC,
    cm.MembershipNumber ASC
SQL,
    ],

    'Q19' => [
        'title' => 'Volunteer personnel who are family members',
        'sql' => <<<'SQL'
SELECT
    p.FirstName,
    p.LastName,
    COUNT(
        DISTINCT CASE
            WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18
            THEN g.MembershipNumber
        END
    ) AS NumberOfAssociatedMinorMembers,
    COUNT(
        DISTINCT CASE
            WHEN participation.GameID IS NOT NULL
            THEN g.MembershipNumber
        END
    ) AS NumberOfMembersParticipatedInFIFAGame,
    p.Phone,
    p.Email,
    l.Name AS CurrentLocationName,
    pos.PositionName AS CurrentRole
FROM Personnel AS p
JOIN FamilyMember AS fm
    ON fm.SSN = p.SSN
JOIN WorksAt AS wa
    ON wa.PersonnelID = p.PersonnelID
   AND wa.EndDate IS NULL
JOIN Location AS l
    ON l.LocationID = wa.LocationID
JOIN Position AS pos
    ON pos.PositionID = wa.PositionID
JOIN Guardianship AS g
    ON g.FamilyMemberID = fm.FamilyMemberID
   AND g.EndDate IS NULL
JOIN ClubMember AS cm
    ON cm.MembershipNumber = g.MembershipNumber
LEFT JOIN Participation AS participation
    ON participation.MembershipNumber = cm.MembershipNumber
WHERE p.Mandate = 'Volunteer'
GROUP BY
    p.PersonnelID,
    p.FirstName,
    p.LastName,
    p.Phone,
    p.Email,
    l.Name,
    pos.PositionName
HAVING COUNT(
    DISTINCT CASE
        WHEN TIMESTAMPDIFF(YEAR, cm.DOB, CURDATE()) < 18
        THEN g.MembershipNumber
    END
) >= 1
AND COUNT(
    DISTINCT CASE
        WHEN participation.GameID IS NOT NULL
        THEN g.MembershipNumber
    END
) >= 1
ORDER BY
    CurrentLocationName ASC,
    CurrentRole ASC,
    p.FirstName ASC,
    p.LastName ASC
SQL,
    ],
];

$pdo = null;
$connectionError = null;

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $DB['host'],
        $DB['port'],
        $DB['name']
    );

    $pdo = new PDO($dsn, $DB['user'], $DB['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $exception) {
    $connectionError = $exception->getMessage();
}


function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function humanize(string $value): string
{
    $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $value) ?? $value;
    $value = str_replace(['_', '-'], ' ', $value);
    return ucwords(trim($value));
}

function qi(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Unsafe SQL identifier.');
    }

    return '`' . $identifier . '`';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(csrf_token(), $submitted)) {
        throw new RuntimeException('The form expired or the CSRF token was invalid. Refresh and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function build_url(array $params = []): string
{
    $base = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
    return $base . ($params === [] ? '' : '?' . http_build_query($params));
}

function redirect_to(array $params = []): void
{
    header('Location: ' . build_url($params));
    exit;
}

function require_database(?PDO $pdo): PDO
{
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('There is no database connection. Update the configuration at the top of index.php.');
    }

    return $pdo;
}

function database_tables(PDO $pdo): array
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $stmt = $pdo->query(
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    );

    $cache[$key] = array_map(
        static fn(array $row): string => (string)$row['TABLE_NAME'],
        $stmt->fetchAll()
    );

    return $cache[$key];
}

function table_exists(PDO $pdo, string $table): bool
{
    return in_array($table, database_tables($pdo), true);
}

function assert_real_table(PDO $pdo, string $table): void
{
    if (!table_exists($pdo, $table)) {
        throw new RuntimeException('Table ' . $table . ' does not exist in the selected database.');
    }
}

function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $table;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    assert_real_table($pdo, $table);
    $stmt = $pdo->query('SHOW FULL COLUMNS FROM ' . qi($table));
    $columns = $stmt->fetchAll();

    $indexed = [];
    foreach ($columns as $column) {
        $indexed[(string)$column['Field']] = $column;
    }

    $cache[$cacheKey] = $indexed;
    return $indexed;
}

function column_names(PDO $pdo, string $table): array
{
    return array_keys(table_columns($pdo, $table));
}

function first_existing_column(PDO $pdo, string $table, array $candidates): ?string
{
    if (!table_exists($pdo, $table)) {
        return null;
    }

    $columns = column_names($pdo, $table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function primary_key_columns(PDO $pdo, string $table): array
{
    assert_real_table($pdo, $table);
    $stmt = $pdo->query('SHOW KEYS FROM ' . qi($table) . " WHERE Key_name = 'PRIMARY'");
    return array_map(
        static fn(array $row): string => (string)$row['Column_name'],
        $stmt->fetchAll()
    );
}

function foreign_keys(PDO $pdo, string $table): array
{
    static $cache = [];
    $cacheKey = spl_object_id($pdo) . ':' . $table;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $stmt->execute(['table_name' => $table]);

    $keys = [];
    foreach ($stmt->fetchAll() as $row) {
        $keys[(string)$row['COLUMN_NAME']] = [
            'table' => (string)$row['REFERENCED_TABLE_NAME'],
            'column' => (string)$row['REFERENCED_COLUMN_NAME'],
        ];
    }

    $cache[$cacheKey] = $keys;
    return $keys;
}

function enum_values(string $type): array
{
    if (!preg_match('/^enum\((.*)\)$/i', $type, $matches)) {
        return [];
    }

    $values = str_getcsv($matches[1], ',', "'", '\\');
    return array_map(static fn(string $value): string => str_replace("\\'", "'", $value), $values);
}

function is_auto_increment(array $column): bool
{
    return str_contains(strtolower((string)($column['Extra'] ?? '')), 'auto_increment');
}

function is_nullable(array $column): bool
{
    return strtoupper((string)($column['Null'] ?? 'NO')) === 'YES';
}

function normalize_form_value(array $column, $rawValue)
{
    $type = strtolower((string)$column['Type']);
    $raw = is_string($rawValue) ? trim($rawValue) : $rawValue;

    if ($raw === '' || $raw === null) {
        if (is_nullable($column)) {
            return null;
        }

        if (($column['Default'] ?? null) !== null) {
            return $column['Default'];
        }

        if (preg_match('/char|text|blob|binary/', $type)) {
            return '';
        }

        throw new InvalidArgumentException(humanize((string)$column['Field']) . ' is required.');
    }

    $enum = enum_values($type);
    if ($enum !== [] && !in_array((string)$raw, $enum, true)) {
        throw new InvalidArgumentException('Invalid value for ' . humanize((string)$column['Field']) . '.');
    }

    if (preg_match('/\b(tinyint|smallint|mediumint|int|bigint)\b/', $type)) {
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException(humanize((string)$column['Field']) . ' must be an integer.');
        }
        return (int)$raw;
    }

    if (preg_match('/\b(decimal|numeric|float|double|real)\b/', $type)) {
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException(humanize((string)$column['Field']) . ' must be numeric.');
        }
        return (string)$raw;
    }

    if (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
        $value = str_replace('T', ' ', (string)$raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException(humanize((string)$column['Field']) . ' must be a valid date and time.');
        }
        return $value;
    }

    if (str_starts_with($type, 'date')) {
        $date = DateTime::createFromFormat('Y-m-d', (string)$raw);
        if (!$date || $date->format('Y-m-d') !== (string)$raw) {
            throw new InvalidArgumentException(humanize((string)$column['Field']) . ' must be a valid date.');
        }
        return (string)$raw;
    }

    return (string)$raw;
}

function pk_from_array(array $source, array $pkColumns): array
{
    $pk = [];
    foreach ($pkColumns as $column) {
        if (!array_key_exists($column, $source)) {
            throw new InvalidArgumentException('Missing primary-key value: ' . $column);
        }
        $pk[$column] = $source[$column];
    }
    return $pk;
}

function build_pk_where(array $pk, array &$params, string $prefix = 'pk'): string
{
    $conditions = [];
    $index = 0;
    foreach ($pk as $column => $value) {
        $param = $prefix . '_' . $index++;
        $conditions[] = qi((string)$column) . ' = :' . $param;
        $params[$param] = $value;
    }

    if ($conditions === []) {
        throw new RuntimeException('The table has no usable primary key.');
    }

    return implode(' AND ', $conditions);
}

function fetch_row_by_pk(PDO $pdo, string $table, array $pk): ?array
{
    $params = [];
    $where = build_pk_where($pk, $params);
    $stmt = $pdo->prepare('SELECT * FROM ' . qi($table) . ' WHERE ' . $where . ' LIMIT 1');
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function reference_options(PDO $pdo, string $table, string $keyColumn): array
{
    if (!table_exists($pdo, $table)) {
        return [];
    }

    $columns = column_names($pdo, $table);
    if (!in_array($keyColumn, $columns, true)) {
        return [];
    }

    $labelParts = [];
    if (in_array('MembershipNumber', $columns, true)) {
        $labelParts[] = 'CAST(' . qi('MembershipNumber') . ' AS CHAR)';
    } elseif (in_array($keyColumn, $columns, true)) {
        $labelParts[] = 'CAST(' . qi($keyColumn) . ' AS CHAR)';
    }

    if (in_array('FirstName', $columns, true) && in_array('LastName', $columns, true)) {
        $labelParts[] = "CONCAT_WS(' ', " . qi('FirstName') . ', ' . qi('LastName') . ')';
    } else {
        foreach (['LocationName', 'Name', 'TeamName', 'PositionName', 'Subject', 'Email'] as $candidate) {
            if (in_array($candidate, $columns, true)) {
                $labelParts[] = qi($candidate);
                break;
            }
        }
    }

    $labelExpression = $labelParts === []
        ? 'CAST(' . qi($keyColumn) . ' AS CHAR)'
        : "CONCAT_WS(' — ', " . implode(', ', $labelParts) . ')';

    $sql = 'SELECT ' . qi($keyColumn) . ' AS option_value, '
        . $labelExpression . ' AS option_label FROM ' . qi($table)
        . ' ORDER BY option_label LIMIT 1000';

    return $pdo->query($sql)->fetchAll();
}

function format_cell($value): string
{
    if ($value === null) {
        return '<span class="muted">NULL</span>';
    }

    $string = (string)$value;
    $length = function_exists('mb_strlen') ? mb_strlen($string) : strlen($string);
    if ($length > 120) {
        $string = function_exists('mb_substr') ? mb_substr($string, 0, 117) . '…' : substr($string, 0, 117) . '…';
    }

    return e($string);
}

function truncate_text(string $value, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
}

function table_row_count(PDO $pdo, string $table): int
{
    assert_real_table($pdo, $table);
    return (int)$pdo->query('SELECT COUNT(*) FROM ' . qi($table))->fetchColumn();
}

function select_rows(PDO $pdo, string $table, int $limit, string $search = ''): array
{
    $columns = table_columns($pdo, $table);
    $params = [];
    $where = '';

    if ($search !== '') {
        $conditions = [];
        foreach ($columns as $columnName => $column) {
            $conditions[] = 'CAST(' . qi($columnName) . ' AS CHAR) LIKE ?';
	    $params[] = '%' . $search . '%';
        }
        if ($conditions !== []) {
            $where = ' WHERE ' . implode(' OR ', $conditions);
        }
    }

    $pk = primary_key_columns($pdo, $table);
    $order = $pk !== [] ? ' ORDER BY ' . implode(', ', array_map('qi', $pk)) . ' DESC' : '';
    $sql = 'SELECT * FROM ' . qi($table) . $where . $order . ' LIMIT ' . max(1, $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetch_simple_options(PDO $pdo, string $table, string $valueColumn, string $labelSql, string $orderSql = ''): array
{
    if (!table_exists($pdo, $table)) {
        return [];
    }

    $sql = 'SELECT ' . qi($valueColumn) . ' AS option_value, ' . $labelSql . ' AS option_label'
        . ' FROM ' . qi($table)
        . ($orderSql !== '' ? ' ORDER BY ' . $orderSql : ' ORDER BY option_label');
    return $pdo->query($sql)->fetchAll();
}

function age_on_date(string $dob, string $date): int
{
    return (new DateTimeImmutable($dob))->diff(new DateTimeImmutable($date))->y;
}

function payment_status_for_session(PDO $pdo, int $membershipNumber, string $sessionDateTime): array
{
    $memberStmt = $pdo->prepare('SELECT DOB FROM ' . qi('ClubMember') . ' WHERE MembershipNumber = :member');
    $memberStmt->execute(['member' => $membershipNumber]);
    $dob = $memberStmt->fetchColumn();
    if ($dob === false) {
        throw new RuntimeException('The selected club member does not exist.');
    }

    $sessionDate = substr($sessionDateTime, 0, 10);
    $year = (int)substr($sessionDate, 0, 4);
    $membershipYear = $sessionYear - 1;
    $age = age_on_date((string)$dob, $sessionDate);
    $required = $age >= 18 ? 200.0 : 100.0;

    $paymentStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(Amount), 0) FROM ' . qi('Payment')
        . ' WHERE MembershipNumber = :member AND MembershipYear = :year'
    );
    $paymentStmt->execute(['member' => $membershipNumber, 'year' => $membershipYear]);
    $paid = (float)$paymentStmt->fetchColumn();

    return [
        'year' => $membershipYear,
        'age' => $age,
        'required' => $required,
        'paid' => $paid,
        'eligible' => $paid >= $required,
    ];
}

function workflow_required_int($value, string $label): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || (int)$validated <= 0) {
        throw new InvalidArgumentException($label . ' is required.');
    }

    return (int)$validated;
}

function workflow_date($value, string $label, bool $required = true): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . ' must be a valid date.');
    }

    return $value;
}

function workflow_period(string $startDate, ?string $endDate, string $label): void
{
    if ($endDate !== null && $endDate < $startDate) {
        throw new InvalidArgumentException($label . ' end date cannot be earlier than the start date.');
    }
}

function workflow_insert_row(PDO $pdo, string $table, array $fields): array
{
    assert_real_table($pdo, $table);
    $columns = table_columns($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);
    $insertColumns = [];
    $placeholders = [];
    $params = [];
    $normalized = [];
    $index = 0;

    foreach ($columns as $columnName => $column) {
        if (is_auto_increment($column)) {
            continue;
        }

        if (!array_key_exists($columnName, $fields)) {
            if (is_nullable($column) || ($column['Default'] ?? null) !== null) {
                continue;
            }
            throw new InvalidArgumentException(humanize($columnName) . ' is required.');
        }

        $value = normalize_form_value($column, $fields[$columnName]);
        $parameter = 'workflow_value_' . $index++;
        $insertColumns[] = qi($columnName);
        $placeholders[] = ':' . $parameter;
        $params[$parameter] = $value;
        $normalized[$columnName] = $value;
    }

    if ($insertColumns === []) {
        $pdo->exec('INSERT INTO ' . qi($table) . ' () VALUES ()');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi($table)
            . ' (' . implode(', ', $insertColumns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
    }

    $pk = [];
    foreach ($pkColumns as $pkColumn) {
        $column = $columns[$pkColumn];
        if (is_auto_increment($column)) {
            $pk[$pkColumn] = (int)$pdo->lastInsertId();
        } elseif (array_key_exists($pkColumn, $normalized)) {
            $pk[$pkColumn] = $normalized[$pkColumn];
        } else {
            throw new RuntimeException('Could not determine the new ' . humanize($pkColumn) . '.');
        }
    }

    return $pk;
}

function workflow_update_row(PDO $pdo, string $table, array $pk, array $fields): void
{
    assert_real_table($pdo, $table);
    $columns = table_columns($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);
    $sets = [];
    $params = [];
    $index = 0;

    foreach ($columns as $columnName => $column) {
        if (in_array($columnName, $pkColumns, true) || !array_key_exists($columnName, $fields)) {
            continue;
        }

        $parameter = 'workflow_value_' . $index++;
        $sets[] = qi($columnName) . ' = :' . $parameter;
        $params[$parameter] = normalize_form_value($column, $fields[$columnName]);
    }

    if ($sets === []) {
        throw new RuntimeException('No editable fields were submitted.');
    }

    $where = build_pk_where($pk, $params, 'workflow_pk');
    $stmt = $pdo->prepare(
        'UPDATE ' . qi($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where
    );
    $stmt->execute($params);
}

function workflow_delete_row(PDO $pdo, string $table, array $pk): void
{
    $params = [];
    $where = build_pk_where($pk, $params, 'workflow_delete_pk');
    $stmt = $pdo->prepare('DELETE FROM ' . qi($table) . ' WHERE ' . $where);
    $stmt->execute($params);
}

function workflow_assert_no_period_overlap(
    PDO $pdo,
    string $table,
    array $owners,
    string $startDate,
    ?string $endDate,
    array $excludePk = []
): void {
    assert_real_table($pdo, $table);
    $conditions = [];
    $params = [];
    $index = 0;

    foreach ($owners as $column => $value) {
        $parameter = 'owner_' . $index++;
        $conditions[] = qi((string)$column) . ' = :' . $parameter;
        $params[$parameter] = $value;
    }

    $conditions[] = "NOT (COALESCE(EndDate, '9999-12-31') < :period_start"
        . " OR COALESCE(:period_end, '9999-12-31') < StartDate)";
    $params['period_start'] = $startDate;
    $params['period_end'] = $endDate;

    if ($excludePk !== []) {
        $excludeConditions = [];
        $excludeIndex = 0;
        foreach ($excludePk as $column => $value) {
            $parameter = 'exclude_' . $excludeIndex++;
            $excludeConditions[] = qi((string)$column) . ' = :' . $parameter;
            $params[$parameter] = $value;
        }
        $conditions[] = 'NOT (' . implode(' AND ', $excludeConditions) . ')';
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . qi($table) . ' WHERE ' . implode(' AND ', $conditions)
    );
    $stmt->execute($params);

    if ((int)$stmt->fetchColumn() > 0) {
        throw new RuntimeException('The selected date period overlaps an existing record.');
    }
}

function workflow_location_options(PDO $pdo): array
{
    return reference_options($pdo, 'Location', 'LocationID');
}

function workflow_position_options(PDO $pdo): array
{
    return reference_options($pdo, 'Position', 'PositionID');
}

function workflow_family_member_options(PDO $pdo): array
{
    return reference_options($pdo, 'FamilyMember', 'FamilyMemberID');
}

function workflow_relationship_values(PDO $pdo): array
{
    $column = table_columns($pdo, 'Guardianship')['RelationshipType'] ?? null;
    if ($column) {
        $values = enum_values((string)$column['Type']);
        if ($values !== []) {
            return $values;
        }
    }

    return ['Father', 'Mother', 'Grandfather', 'Grandmother', 'Tutor', 'Partner', 'Friend', 'Other'];
}

function workflow_relationship_is_current(string $startDate, ?string $endDate, ?string $asOf = null): bool
{
    $asOf = $asOf ?? date('Y-m-d');
    return $startDate <= $asOf && ($endDate === null || $endDate >= $asOf);
}

function workflow_member_is_minor(PDO $pdo, int $membershipNumber, ?string $asOf = null): bool
{
    $stmt = $pdo->prepare('SELECT DOB FROM ' . qi('ClubMember') . ' WHERE MembershipNumber = :member');
    $stmt->execute(['member' => $membershipNumber]);
    $dob = $stmt->fetchColumn();
    if ($dob === false) {
        throw new RuntimeException('The selected club member does not exist.');
    }

    $asOf = $asOf ?? date('Y-m-d');
    return age_on_date((string)$dob, $asOf) < 18;
}

function workflow_assert_minor_keeps_current_guardian(
    PDO $pdo,
    int $membershipNumber,
    int $familyMemberId,
    string $startDate
): void {
    if (!workflow_member_is_minor($pdo, $membershipNumber)) {
        return;
    }

    $rowStmt = $pdo->prepare(
        'SELECT StartDate, EndDate FROM ' . qi('Guardianship')
        . ' WHERE MembershipNumber = :member AND FamilyMemberID = :family_member AND StartDate = :start_date'
    );
    $rowStmt->execute([
        'member' => $membershipNumber,
        'family_member' => $familyMemberId,
        'start_date' => $startDate,
    ]);
    $row = $rowStmt->fetch();
    if ($row === false || !workflow_relationship_is_current((string)$row['StartDate'], $row['EndDate'] === null ? null : (string)$row['EndDate'])) {
        return;
    }

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . qi('Guardianship')
        . ' WHERE MembershipNumber = :member'
        . ' AND StartDate <= CURDATE() AND (EndDate IS NULL OR EndDate >= CURDATE())'
        . ' AND NOT (FamilyMemberID = :family_member AND StartDate = :start_date)'
    );
    $countStmt->execute([
        'member' => $membershipNumber,
        'family_member' => $familyMemberId,
        'start_date' => $startDate,
    ]);

    if ((int)$countStmt->fetchColumn() === 0) {
        throw new RuntimeException('This relationship cannot be removed because the minor member would have no current family member.');
    }
}

function workflow_location_label_expression(PDO $pdo, string $alias = 'l'): string
{
    $column = first_existing_column($pdo, 'Location', ['LocationName', 'Name']);
    if ($column !== null) {
        return $alias . '.' . qi($column);
    }

    return "CONCAT('Location #', " . $alias . '.' . qi('LocationID') . ')';
}

function workflow_render_base_fields(PDO $pdo, string $table, array $row, bool $editing): void
{
    $columns = table_columns($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);

    foreach ($columns as $columnName => $column) {
        if (!$editing && is_auto_increment($column)) {
            continue;
        }
        render_generic_field(
            $pdo,
            $table,
            $columnName,
            $column,
            $row[$columnName] ?? ($column['Default'] ?? ''),
            $editing,
            $pkColumns
        );
    }
}

function handle_personnel_save(PDO $pdo): void
{
    foreach (['Personnel', 'WorksAt', 'Location', 'Position'] as $table) {
        assert_real_table($pdo, $table);
    }

    $mode = (string)($_POST['mode'] ?? 'insert');
    $fields = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];

    if ($mode === 'update') {
        $personnelId = workflow_required_int($_POST['personnel_id'] ?? null, 'Personnel');
        workflow_update_row($pdo, 'Personnel', ['PersonnelID' => $personnelId], $fields);
        flash('success', 'Personnel record updated successfully.');
        redirect_to(['page' => 'personnel']);
    }

    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Initial location');
    $positionId = workflow_required_int($_POST['position_id'] ?? null, 'Initial position');
    $startDate = workflow_date($_POST['assignment_start_date'] ?? '', 'Assignment start date');
    $endDate = workflow_date($_POST['assignment_end_date'] ?? '', 'Assignment end date', false);
    workflow_period($startDate, $endDate, 'Personnel assignment');

    $pdo->beginTransaction();
    try {
        $pk = workflow_insert_row($pdo, 'Personnel', $fields);
        $personnelId = (int)($pk['PersonnelID'] ?? 0);
        if ($personnelId <= 0) {
            throw new RuntimeException('Could not determine the new personnel ID.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi('WorksAt')
            . ' (PersonnelID, LocationID, StartDate, PositionID, EndDate)'
            . ' VALUES (:personnel, :location, :start_date, :position, :end_date)'
        );
        $stmt->execute([
            'personnel' => $personnelId,
            'location' => $locationId,
            'start_date' => $startDate,
            'position' => $positionId,
            'end_date' => $endDate,
        ]);

        $pdo->commit();
        flash('success', 'Personnel record and initial assignment created successfully.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'personnel']);
}

function handle_personnel_delete(PDO $pdo): void
{
    $personnelId = workflow_required_int($_POST['personnel_id'] ?? null, 'Personnel');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM ' . qi('WorksAt') . ' WHERE PersonnelID = :personnel');
        $stmt->execute(['personnel' => $personnelId]);
        workflow_delete_row($pdo, 'Personnel', ['PersonnelID' => $personnelId]);
        $pdo->commit();
        flash('success', 'Personnel record deleted.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'personnel']);
}

function handle_worksat_save(PDO $pdo): void
{
    foreach (['WorksAt', 'Personnel', 'Location', 'Position'] as $table) {
        assert_real_table($pdo, $table);
    }

    $mode = (string)($_POST['mode'] ?? 'insert');
    $personnelId = workflow_required_int($_POST['personnel_id'] ?? null, 'Personnel');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $positionId = workflow_required_int($_POST['position_id'] ?? null, 'Position');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $endDate = workflow_date($_POST['end_date'] ?? '', 'End date', false);
    workflow_period($startDate, $endDate, 'Personnel assignment');

    $exclude = [];
    if ($mode === 'update') {
        $exclude = [
            'PersonnelID' => $personnelId,
            'LocationID' => workflow_required_int($_POST['old_location_id'] ?? null, 'Original location'),
            'StartDate' => workflow_date($_POST['old_start_date'] ?? '', 'Original start date'),
        ];
    }

    workflow_assert_no_period_overlap(
        $pdo,
        'WorksAt',
        ['PersonnelID' => $personnelId],
        $startDate,
        $endDate,
        $exclude
    );

    if ($mode === 'update') {
        $stmt = $pdo->prepare(
            'UPDATE ' . qi('WorksAt')
            . ' SET LocationID = :location, StartDate = :start_date, PositionID = :position, EndDate = :end_date'
            . ' WHERE PersonnelID = :personnel AND LocationID = :old_location AND StartDate = :old_start_date'
        );
        $stmt->execute([
            'location' => $locationId,
            'start_date' => $startDate,
            'position' => $positionId,
            'end_date' => $endDate,
            'personnel' => $personnelId,
            'old_location' => $exclude['LocationID'],
            'old_start_date' => $exclude['StartDate'],
        ]);
        flash('success', 'Personnel assignment updated.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi('WorksAt')
            . ' (PersonnelID, LocationID, StartDate, PositionID, EndDate)'
            . ' VALUES (:personnel, :location, :start_date, :position, :end_date)'
        );
        $stmt->execute([
            'personnel' => $personnelId,
            'location' => $locationId,
            'start_date' => $startDate,
            'position' => $positionId,
            'end_date' => $endDate,
        ]);
        flash('success', 'Personnel assignment added.');
    }

    redirect_to(['page' => 'personnel', 'mode' => 'assignments', 'id' => $personnelId]);
}

function handle_worksat_delete(PDO $pdo): void
{
    $personnelId = workflow_required_int($_POST['personnel_id'] ?? null, 'Personnel');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');

    $stmt = $pdo->prepare(
        'DELETE FROM ' . qi('WorksAt')
        . ' WHERE PersonnelID = :personnel AND LocationID = :location AND StartDate = :start_date'
    );
    $stmt->execute([
        'personnel' => $personnelId,
        'location' => $locationId,
        'start_date' => $startDate,
    ]);

    flash('success', 'Personnel assignment deleted.');
    redirect_to(['page' => 'personnel', 'mode' => 'assignments', 'id' => $personnelId]);
}

function handle_family_member_save(PDO $pdo): void
{
    foreach (['FamilyMember', 'FamilyMemberLocation', 'Guardianship', 'Location', 'ClubMember'] as $table) {
        assert_real_table($pdo, $table);
    }

    $mode = (string)($_POST['mode'] ?? 'insert');
    $fields = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];

    if ($mode === 'update') {
        $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
        workflow_update_row($pdo, 'FamilyMember', ['FamilyMemberID' => $familyMemberId], $fields);
        flash('success', 'Family member updated successfully.');
        redirect_to(['page' => 'family_members']);
    }

    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Initial location');
    $locationStart = workflow_date($_POST['location_start_date'] ?? '', 'Location start date');
    $locationEnd = workflow_date($_POST['location_end_date'] ?? '', 'Location end date', false);
    workflow_period($locationStart, $locationEnd, 'Family-member location');

    $membershipRaw = trim((string)($_POST['membership_number'] ?? ''));
    $membershipNumber = $membershipRaw === '' ? null : workflow_required_int($membershipRaw, 'Associated club member');
    $relationshipType = trim((string)($_POST['relationship_type'] ?? ''));
    $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : 1;
    $relationshipStart = null;
    $relationshipEnd = null;

    if ($membershipNumber !== null) {
        $relationshipStart = workflow_date($_POST['relationship_start_date'] ?? '', 'Relationship start date');
        $relationshipEnd = workflow_date($_POST['relationship_end_date'] ?? '', 'Relationship end date', false);
        workflow_period($relationshipStart, $relationshipEnd, 'Family relationship');
        if (!in_array($relationshipType, workflow_relationship_values($pdo), true)) {
            throw new InvalidArgumentException('Select a valid relationship type.');
        }
        if (!in_array($isPrimary, [0, 1], true)) {
            throw new InvalidArgumentException('Primary/Secondary selection is invalid.');
        }
    }

    $pdo->beginTransaction();
    try {
        $pk = workflow_insert_row($pdo, 'FamilyMember', $fields);
        $familyMemberId = (int)($pk['FamilyMemberID'] ?? 0);
        if ($familyMemberId <= 0) {
            throw new RuntimeException('Could not determine the new family member ID.');
        }

        $locationStmt = $pdo->prepare(
            'INSERT INTO ' . qi('FamilyMemberLocation')
            . ' (FamilyMemberID, LocationID, StartDate, EndDate)'
            . ' VALUES (:family_member, :location, :start_date, :end_date)'
        );
        $locationStmt->execute([
            'family_member' => $familyMemberId,
            'location' => $locationId,
            'start_date' => $locationStart,
            'end_date' => $locationEnd,
        ]);

        if ($membershipNumber !== null) {
            $guardianStmt = $pdo->prepare(
                'INSERT INTO ' . qi('Guardianship')
                . ' (MembershipNumber, FamilyMemberID, StartDate, RelationshipType, IsPrimary, EndDate)'
                . ' VALUES (:member, :family_member, :start_date, :relationship, :is_primary, :end_date)'
            );
            $guardianStmt->execute([
                'member' => $membershipNumber,
                'family_member' => $familyMemberId,
                'start_date' => $relationshipStart,
                'relationship' => $relationshipType,
                'is_primary' => $isPrimary,
                'end_date' => $relationshipEnd,
            ]);
        }

        $pdo->commit();
        flash('success', 'Family member and related records created successfully.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'family_members']);
}

function handle_family_member_delete(PDO $pdo): void
{
    $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
    $relationshipStmt = $pdo->prepare(
        'SELECT DISTINCT MembershipNumber FROM ' . qi('Guardianship')
        . ' WHERE FamilyMemberID = :family_member'
        . ' AND StartDate <= CURDATE() AND (EndDate IS NULL OR EndDate >= CURDATE())'
    );
    $relationshipStmt->execute(['family_member' => $familyMemberId]);
    foreach ($relationshipStmt->fetchAll() as $relationship) {
        $membershipNumber = (int)$relationship['MembershipNumber'];
        if (!workflow_member_is_minor($pdo, $membershipNumber)) {
            continue;
        }
        $otherStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . qi('Guardianship')
            . ' WHERE MembershipNumber = :member AND FamilyMemberID <> :family_member'
            . ' AND StartDate <= CURDATE() AND (EndDate IS NULL OR EndDate >= CURDATE())'
        );
        $otherStmt->execute(['member' => $membershipNumber, 'family_member' => $familyMemberId]);
        if ((int)$otherStmt->fetchColumn() === 0) {
            throw new RuntimeException(
                'This family member cannot be deleted because minor member #'
                . $membershipNumber . ' would have no current family member.'
            );
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM ' . qi('Guardianship') . ' WHERE FamilyMemberID = :family_member');
        $stmt->execute(['family_member' => $familyMemberId]);
        $stmt = $pdo->prepare('DELETE FROM ' . qi('FamilyMemberLocation') . ' WHERE FamilyMemberID = :family_member');
        $stmt->execute(['family_member' => $familyMemberId]);
        workflow_delete_row($pdo, 'FamilyMember', ['FamilyMemberID' => $familyMemberId]);
        $pdo->commit();
        flash('success', 'Family member deleted.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'family_members']);
}

function handle_family_location_save(PDO $pdo): void
{
    $mode = (string)($_POST['mode'] ?? 'insert');
    $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $endDate = workflow_date($_POST['end_date'] ?? '', 'End date', false);
    workflow_period($startDate, $endDate, 'Family-member location');

    $exclude = [];
    if ($mode === 'update') {
        $exclude = [
            'FamilyMemberID' => $familyMemberId,
            'LocationID' => workflow_required_int($_POST['old_location_id'] ?? null, 'Original location'),
            'StartDate' => workflow_date($_POST['old_start_date'] ?? '', 'Original start date'),
        ];
    }

    workflow_assert_no_period_overlap(
        $pdo,
        'FamilyMemberLocation',
        ['FamilyMemberID' => $familyMemberId],
        $startDate,
        $endDate,
        $exclude
    );

    if ($mode === 'update') {
        $stmt = $pdo->prepare(
            'UPDATE ' . qi('FamilyMemberLocation')
            . ' SET LocationID = :location, StartDate = :start_date, EndDate = :end_date'
            . ' WHERE FamilyMemberID = :family_member AND LocationID = :old_location AND StartDate = :old_start_date'
        );
        $stmt->execute([
            'location' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'family_member' => $familyMemberId,
            'old_location' => $exclude['LocationID'],
            'old_start_date' => $exclude['StartDate'],
        ]);
        flash('success', 'Family-member location updated.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi('FamilyMemberLocation')
            . ' (FamilyMemberID, LocationID, StartDate, EndDate)'
            . ' VALUES (:family_member, :location, :start_date, :end_date)'
        );
        $stmt->execute([
            'family_member' => $familyMemberId,
            'location' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        flash('success', 'Family-member location added.');
    }

    redirect_to(['page' => 'family_members', 'mode' => 'locations', 'id' => $familyMemberId]);
}

function handle_family_location_delete(PDO $pdo): void
{
    $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $stmt = $pdo->prepare(
        'DELETE FROM ' . qi('FamilyMemberLocation')
        . ' WHERE FamilyMemberID = :family_member AND LocationID = :location AND StartDate = :start_date'
    );
    $stmt->execute([
        'family_member' => $familyMemberId,
        'location' => $locationId,
        'start_date' => $startDate,
    ]);
    flash('success', 'Family-member location deleted.');
    redirect_to(['page' => 'family_members', 'mode' => 'locations', 'id' => $familyMemberId]);
}

function handle_guardianship_save(PDO $pdo): void
{
    foreach (['Guardianship', 'FamilyMember', 'ClubMember'] as $table) {
        assert_real_table($pdo, $table);
    }

    $mode = (string)($_POST['mode'] ?? 'insert');
    $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $endDate = workflow_date($_POST['end_date'] ?? '', 'End date', false);
    workflow_period($startDate, $endDate, 'Family relationship');
    $relationshipType = trim((string)($_POST['relationship_type'] ?? ''));
    $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : -1;

    if (!in_array($relationshipType, workflow_relationship_values($pdo), true)) {
        throw new InvalidArgumentException('Select a valid relationship type.');
    }
    if (!in_array($isPrimary, [0, 1], true)) {
        throw new InvalidArgumentException('Select Primary or Secondary.');
    }

    $exclude = [];
    if ($mode === 'update') {
        $exclude = [
            'MembershipNumber' => workflow_required_int($_POST['old_membership_number'] ?? null, 'Original club member'),
            'FamilyMemberID' => workflow_required_int($_POST['old_family_member_id'] ?? null, 'Original family member'),
            'StartDate' => workflow_date($_POST['old_start_date'] ?? '', 'Original start date'),
        ];

        $sameMemberKeepsCurrentRelationship =
            $exclude['MembershipNumber'] === $membershipNumber
            && workflow_relationship_is_current($startDate, $endDate);
        if (!$sameMemberKeepsCurrentRelationship) {
            workflow_assert_minor_keeps_current_guardian(
                $pdo,
                (int)$exclude['MembershipNumber'],
                (int)$exclude['FamilyMemberID'],
                (string)$exclude['StartDate']
            );
        }
    }

    workflow_assert_no_period_overlap(
        $pdo,
        'Guardianship',
        ['MembershipNumber' => $membershipNumber, 'FamilyMemberID' => $familyMemberId],
        $startDate,
        $endDate,
        $exclude
    );

    $pdo->beginTransaction();
    try {
        if ($mode === 'update') {
            $stmt = $pdo->prepare(
                'UPDATE ' . qi('Guardianship')
                . ' SET MembershipNumber = :member, FamilyMemberID = :family_member, StartDate = :start_date,'
                . ' RelationshipType = :relationship, IsPrimary = :is_primary, EndDate = :end_date'
                . ' WHERE MembershipNumber = :old_member AND FamilyMemberID = :old_family_member AND StartDate = :old_start_date'
            );
            $stmt->execute([
                'member' => $membershipNumber,
                'family_member' => $familyMemberId,
                'start_date' => $startDate,
                'relationship' => $relationshipType,
                'is_primary' => $isPrimary,
                'end_date' => $endDate,
                'old_member' => $exclude['MembershipNumber'],
                'old_family_member' => $exclude['FamilyMemberID'],
                'old_start_date' => $exclude['StartDate'],
            ]);
            flash('success', 'Family relationship updated.');
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO ' . qi('Guardianship')
                . ' (MembershipNumber, FamilyMemberID, StartDate, RelationshipType, IsPrimary, EndDate)'
                . ' VALUES (:member, :family_member, :start_date, :relationship, :is_primary, :end_date)'
            );
            $stmt->execute([
                'member' => $membershipNumber,
                'family_member' => $familyMemberId,
                'start_date' => $startDate,
                'relationship' => $relationshipType,
                'is_primary' => $isPrimary,
                'end_date' => $endDate,
            ]);
            flash('success', 'Family relationship added.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $returnPage = (string)($_POST['relationship_return_page'] ?? 'family_members');
    if ($returnPage === 'club_members') {
        redirect_to(['page' => 'club_members', 'mode' => 'guardians', 'id' => $membershipNumber]);
    }
    redirect_to(['page' => 'family_members', 'mode' => 'relationships', 'id' => $familyMemberId]);
}

function handle_guardianship_delete(PDO $pdo): void
{
    $familyMemberId = workflow_required_int($_POST['family_member_id'] ?? null, 'Family member');
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');

    workflow_assert_minor_keeps_current_guardian($pdo, $membershipNumber, $familyMemberId, $startDate);

    $stmt = $pdo->prepare(
        'DELETE FROM ' . qi('Guardianship')
        . ' WHERE MembershipNumber = :member AND FamilyMemberID = :family_member AND StartDate = :start_date'
    );
    $stmt->execute([
        'member' => $membershipNumber,
        'family_member' => $familyMemberId,
        'start_date' => $startDate,
    ]);
    flash('success', 'Family relationship deleted.');

    $returnPage = (string)($_POST['relationship_return_page'] ?? 'family_members');
    if ($returnPage === 'club_members') {
        redirect_to(['page' => 'club_members', 'mode' => 'guardians', 'id' => $membershipNumber]);
    }
    redirect_to(['page' => 'family_members', 'mode' => 'relationships', 'id' => $familyMemberId]);
}

function handle_club_member_save(PDO $pdo): void
{
    foreach (['ClubMember', 'ClubMemberLocation', 'Guardianship', 'Location', 'FamilyMember'] as $table) {
        assert_real_table($pdo, $table);
    }

    $mode = (string)($_POST['mode'] ?? 'insert');
    $fields = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];
    $dob = workflow_date($fields['DOB'] ?? '', 'Date of birth');
    $currentAge = age_on_date($dob, date('Y-m-d'));

    if ($mode === 'update') {
        if ($currentAge < 4) {
            throw new InvalidArgumentException('A club member must be at least four years old.');
        }
        $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
        $pdo->beginTransaction();
        try {
            workflow_update_row($pdo, 'ClubMember', ['MembershipNumber' => $membershipNumber], $fields);
            if ($currentAge < 18) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM ' . qi('Guardianship')
                    . ' WHERE MembershipNumber = :member'
                    . ' AND StartDate <= CURDATE() AND (EndDate IS NULL OR EndDate >= CURDATE())'
                );
                $stmt->execute(['member' => $membershipNumber]);
                if ((int)$stmt->fetchColumn() === 0) {
                    throw new RuntimeException('A minor club member must have at least one current family-member relationship.');
                }
            }
            $pdo->commit();
            flash('success', 'Club member updated successfully.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        redirect_to(['page' => 'club_members']);
    }

    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Initial location');
    $locationStart = workflow_date($_POST['location_start_date'] ?? '', 'Location start date');
    $locationEnd = workflow_date($_POST['location_end_date'] ?? '', 'Location end date', false);
    workflow_period($locationStart, $locationEnd, 'Club-member location');

    $registrationAge = age_on_date($dob, $locationStart);
    if ($registrationAge < 4) {
        throw new InvalidArgumentException('A new club member must be at least four years old on the registration date.');
    }

    $familyRaw = trim((string)($_POST['family_member_id'] ?? ''));
    $familyMemberId = $familyRaw === '' ? null : workflow_required_int($familyRaw, 'Family member');
    if ($registrationAge < 18 && $familyMemberId === null) {
        throw new InvalidArgumentException('A minor club member must be associated with a family member.');
    }

    $relationshipType = trim((string)($_POST['relationship_type'] ?? ''));
    $isPrimary = isset($_POST['is_primary']) ? (int)$_POST['is_primary'] : 1;
    $relationshipStart = null;
    $relationshipEnd = null;
    if ($familyMemberId !== null) {
        $relationshipStart = workflow_date($_POST['relationship_start_date'] ?? '', 'Relationship start date');
        $relationshipEnd = workflow_date($_POST['relationship_end_date'] ?? '', 'Relationship end date', false);
        workflow_period($relationshipStart, $relationshipEnd, 'Family relationship');
        if (!in_array($relationshipType, workflow_relationship_values($pdo), true)) {
            throw new InvalidArgumentException('Select a valid relationship type.');
        }
        if (!in_array($isPrimary, [0, 1], true)) {
            throw new InvalidArgumentException('Select Primary or Secondary.');
        }
        if ($registrationAge < 18
            && !workflow_relationship_is_current($relationshipStart, $relationshipEnd, $locationStart)) {
            throw new InvalidArgumentException('A minor club member must have a family relationship active on the registration date.');
        }
    }

    $pdo->beginTransaction();
    try {
        $pk = workflow_insert_row($pdo, 'ClubMember', $fields);
        $membershipNumber = (int)($pk['MembershipNumber'] ?? 0);
        if ($membershipNumber <= 0) {
            throw new RuntimeException('Could not determine the new membership number.');
        }

        $locationStmt = $pdo->prepare(
            'INSERT INTO ' . qi('ClubMemberLocation')
            . ' (MembershipNumber, LocationID, StartDate, EndDate)'
            . ' VALUES (:member, :location, :start_date, :end_date)'
        );
        $locationStmt->execute([
            'member' => $membershipNumber,
            'location' => $locationId,
            'start_date' => $locationStart,
            'end_date' => $locationEnd,
        ]);

        if ($familyMemberId !== null) {
            $guardianStmt = $pdo->prepare(
                'INSERT INTO ' . qi('Guardianship')
                . ' (MembershipNumber, FamilyMemberID, StartDate, RelationshipType, IsPrimary, EndDate)'
                . ' VALUES (:member, :family_member, :start_date, :relationship, :is_primary, :end_date)'
            );
            $guardianStmt->execute([
                'member' => $membershipNumber,
                'family_member' => $familyMemberId,
                'start_date' => $relationshipStart,
                'relationship' => $relationshipType,
                'is_primary' => $isPrimary,
                'end_date' => $relationshipEnd,
            ]);
        }

        $pdo->commit();
        flash('success', 'Club member, location, and family relationship were created successfully.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'club_members']);
}

function handle_club_member_delete(PDO $pdo): void
{
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM ' . qi('Guardianship') . ' WHERE MembershipNumber = :member');
        $stmt->execute(['member' => $membershipNumber]);
        $stmt = $pdo->prepare('DELETE FROM ' . qi('ClubMemberLocation') . ' WHERE MembershipNumber = :member');
        $stmt->execute(['member' => $membershipNumber]);
        workflow_delete_row($pdo, 'ClubMember', ['MembershipNumber' => $membershipNumber]);
        $pdo->commit();
        flash('success', 'Club member deleted.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'club_members']);
}

function handle_club_location_save(PDO $pdo): void
{
    $mode = (string)($_POST['mode'] ?? 'insert');
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $endDate = workflow_date($_POST['end_date'] ?? '', 'End date', false);
    workflow_period($startDate, $endDate, 'Club-member location');

    $exclude = [];
    if ($mode === 'update') {
        $exclude = [
            'MembershipNumber' => $membershipNumber,
            'LocationID' => workflow_required_int($_POST['old_location_id'] ?? null, 'Original location'),
            'StartDate' => workflow_date($_POST['old_start_date'] ?? '', 'Original start date'),
        ];
    }

    workflow_assert_no_period_overlap(
        $pdo,
        'ClubMemberLocation',
        ['MembershipNumber' => $membershipNumber],
        $startDate,
        $endDate,
        $exclude
    );

    if ($mode === 'update') {
        $stmt = $pdo->prepare(
            'UPDATE ' . qi('ClubMemberLocation')
            . ' SET LocationID = :location, StartDate = :start_date, EndDate = :end_date'
            . ' WHERE MembershipNumber = :member AND LocationID = :old_location AND StartDate = :old_start_date'
        );
        $stmt->execute([
            'location' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'member' => $membershipNumber,
            'old_location' => $exclude['LocationID'],
            'old_start_date' => $exclude['StartDate'],
        ]);
        flash('success', 'Club-member location updated.');
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi('ClubMemberLocation')
            . ' (MembershipNumber, LocationID, StartDate, EndDate)'
            . ' VALUES (:member, :location, :start_date, :end_date)'
        );
        $stmt->execute([
            'member' => $membershipNumber,
            'location' => $locationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        flash('success', 'Club-member location added.');
    }

    redirect_to(['page' => 'club_members', 'mode' => 'locations', 'id' => $membershipNumber]);
}

function handle_club_location_delete(PDO $pdo): void
{
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $locationId = workflow_required_int($_POST['location_id'] ?? null, 'Location');
    $startDate = workflow_date($_POST['start_date'] ?? '', 'Start date');
    $stmt = $pdo->prepare(
        'DELETE FROM ' . qi('ClubMemberLocation')
        . ' WHERE MembershipNumber = :member AND LocationID = :location AND StartDate = :start_date'
    );
    $stmt->execute([
        'member' => $membershipNumber,
        'location' => $locationId,
        'start_date' => $startDate,
    ]);
    flash('success', 'Club-member location deleted.');
    redirect_to(['page' => 'club_members', 'mode' => 'locations', 'id' => $membershipNumber]);
}


function handle_crud_save(PDO $pdo): void
{
    $table = (string)($_POST['table'] ?? '');
    $mode = (string)($_POST['mode'] ?? 'insert');
    assert_real_table($pdo, $table);

    $columns = table_columns($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);
    $fields = is_array($_POST['field'] ?? null) ? $_POST['field'] : [];

    if ($mode === 'update') {
        $originalPk = is_array($_POST['pk'] ?? null) ? pk_from_array($_POST['pk'], $pkColumns) : [];
        $sets = [];
        $params = [];
        $index = 0;

        foreach ($columns as $columnName => $column) {
            if (in_array($columnName, $pkColumns, true)) {
                continue;
            }
            if (!array_key_exists($columnName, $fields)) {
                continue;
            }

            $value = normalize_form_value($column, $fields[$columnName]);
            $param = 'value_' . $index++;
            $sets[] = qi($columnName) . ' = :' . $param;
            $params[$param] = $value;
        }

        if ($sets === []) {
            throw new RuntimeException('No editable fields were submitted.');
        }

        $where = build_pk_where($originalPk, $params, 'original_pk');
        $sql = 'UPDATE ' . qi($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        flash('success', humanize($table) . ' updated successfully.');
        redirect_to(['page' => 'table', 'table' => $table]);
    }

    $insertColumns = [];
    $placeholders = [];
    $params = [];
    $index = 0;

    foreach ($columns as $columnName => $column) {
        if (is_auto_increment($column)) {
            continue;
        }

        if (!array_key_exists($columnName, $fields)) {
            if (is_nullable($column) || ($column['Default'] ?? null) !== null) {
                continue;
            }
            throw new InvalidArgumentException(humanize($columnName) . ' is required.');
        }

        $value = normalize_form_value($column, $fields[$columnName]);
        $param = 'value_' . $index++;
        $insertColumns[] = qi($columnName);
        $placeholders[] = ':' . $param;
        $params[$param] = $value;
    }

    if ($insertColumns === []) {
        $sql = 'INSERT INTO ' . qi($table) . ' () VALUES ()';
        $pdo->exec($sql);
    } else {
        $sql = 'INSERT INTO ' . qi($table)
            . ' (' . implode(', ', $insertColumns) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    flash('success', humanize($table) . ' created successfully.');
    redirect_to(['page' => 'table', 'table' => $table]);
}

function handle_crud_delete(PDO $pdo): void
{
    $table = (string)($_POST['table'] ?? '');
    assert_real_table($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);
    $pk = is_array($_POST['pk'] ?? null) ? pk_from_array($_POST['pk'], $pkColumns) : [];

    $params = [];
    $where = build_pk_where($pk, $params);
    $stmt = $pdo->prepare('DELETE FROM ' . qi($table) . ' WHERE ' . $where);
    $stmt->execute($params);

    flash('success', humanize($table) . ' deleted successfully.');
    redirect_to(['page' => 'table', 'table' => $table]);
}


function handle_create_session_with_formations(PDO $pdo): void
{
    foreach (['Session', 'TeamFormation', 'Team', 'Personnel'] as $table) {
        assert_real_table($pdo, $table);
    }

    $dateTime = trim((string)($_POST['session_datetime'] ?? ''));
    $dateTime = str_replace('T', ' ', $dateTime);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dateTime)) {
        $dateTime .= ':00';
    }
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
    if (!$date || $date->format('Y-m-d H:i:s') !== $dateTime) {
        throw new InvalidArgumentException('Enter a valid session date and time.');
    }

    $address = trim((string)($_POST['address'] ?? ''));
    $type = (string)($_POST['session_type'] ?? '');
    $team1 = filter_var($_POST['team_1'] ?? null, FILTER_VALIDATE_INT);
    $team2 = filter_var($_POST['team_2'] ?? null, FILTER_VALIDATE_INT);
    $coach1 = filter_var($_POST['coach_1'] ?? null, FILTER_VALIDATE_INT);
    $coach2 = filter_var($_POST['coach_2'] ?? null, FILTER_VALIDATE_INT);
    $score1Raw = trim((string)($_POST['score_1'] ?? ''));
    $score2Raw = trim((string)($_POST['score_2'] ?? ''));

    if ($address === '') {
        throw new InvalidArgumentException('Session address is required.');
    }
    if (!in_array($type, ['Training', 'Game'], true)) {
        throw new InvalidArgumentException('Session type must be Training or Game.');
    }
    if ($team1 === false || $team2 === false || $coach1 === false || $coach2 === false) {
        throw new InvalidArgumentException('Select two teams and two head coaches.');
    }
    if ($team1 === $team2) {
        throw new InvalidArgumentException('A session must contain two different teams.');
    }

    $score1 = $score1Raw === '' ? null : filter_var($score1Raw, FILTER_VALIDATE_INT);
    $score2 = $score2Raw === '' ? null : filter_var($score2Raw, FILTER_VALIDATE_INT);
    if ($score1 === false || $score2 === false || ($score1 !== null && $score1 < 0) || ($score2 !== null && $score2 < 0)) {
        throw new InvalidArgumentException('Scores must be blank or non-negative integers.');
    }
    if ($type === 'Training') {
        $score1 = null;
        $score2 = null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . qi('Session') . ' (SessionDateTime, Address, SessionType)'
            . ' VALUES (:session_datetime, :address, :session_type)'
        );
        $stmt->execute([
            'session_datetime' => $dateTime,
            'address' => $address,
            'session_type' => $type,
        ]);
        $sessionId = (int)$pdo->lastInsertId();

        $formationStmt = $pdo->prepare(
            'INSERT INTO ' . qi('TeamFormation') . ' (SessionID, TeamID, HeadCoachID, Score)'
            . ' VALUES (:session_id, :team_id, :coach_id, :score)'
        );
        $formationStmt->execute([
            'session_id' => $sessionId,
            'team_id' => $team1,
            'coach_id' => $coach1,
            'score' => $score1,
        ]);
        $formationStmt->execute([
            'session_id' => $sessionId,
            'team_id' => $team2,
            'coach_id' => $coach2,
            'score' => $score2,
        ]);

        $pdo->commit();
        flash('success', 'Session #' . $sessionId . ' and both team formations were created.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'formations']);
}

function workflow_session_form_input(): array
{
    $dateTime = trim((string)($_POST['session_datetime'] ?? ''));
    $dateTime = str_replace('T', ' ', $dateTime);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dateTime)) {
        $dateTime .= ':00';
    }
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
    if (!$date || $date->format('Y-m-d H:i:s') !== $dateTime) {
        throw new InvalidArgumentException('Enter a valid session date and time.');
    }

    $address = trim((string)($_POST['address'] ?? ''));
    $type = (string)($_POST['session_type'] ?? '');
    $team1 = workflow_required_int($_POST['team_1'] ?? null, 'Team 1');
    $team2 = workflow_required_int($_POST['team_2'] ?? null, 'Team 2');
    $coach1 = workflow_required_int($_POST['coach_1'] ?? null, 'Team 1 head coach');
    $coach2 = workflow_required_int($_POST['coach_2'] ?? null, 'Team 2 head coach');
    $score1Raw = trim((string)($_POST['score_1'] ?? ''));
    $score2Raw = trim((string)($_POST['score_2'] ?? ''));

    if ($address === '') {
        throw new InvalidArgumentException('Session address is required.');
    }
    if (!in_array($type, ['Training', 'Game'], true)) {
        throw new InvalidArgumentException('Session type must be Training or Game.');
    }
    if ($team1 === $team2) {
        throw new InvalidArgumentException('A session must contain two different teams.');
    }

    $score1 = $score1Raw === '' ? null : filter_var($score1Raw, FILTER_VALIDATE_INT);
    $score2 = $score2Raw === '' ? null : filter_var($score2Raw, FILTER_VALIDATE_INT);
    if ($score1 === false || $score2 === false || ($score1 !== null && $score1 < 0) || ($score2 !== null && $score2 < 0)) {
        throw new InvalidArgumentException('Scores must be blank or non-negative integers.');
    }
    if ($type === 'Training') {
        $score1 = null;
        $score2 = null;
    }

    return [
        'session_datetime' => $dateTime,
        'address' => $address,
        'session_type' => $type,
        'formations' => [
            ['team_id' => $team1, 'coach_id' => $coach1, 'score' => $score1],
            ['team_id' => $team2, 'coach_id' => $coach2, 'score' => $score2],
        ],
    ];
}

function workflow_session_bundle(PDO $pdo, int $sessionId): array
{
    $sessionStmt = $pdo->prepare(
        'SELECT SessionID, SessionDateTime, Address, SessionType FROM ' . qi('Session')
        . ' WHERE SessionID = :session'
    );
    $sessionStmt->execute(['session' => $sessionId]);
    $session = $sessionStmt->fetch();
    if ($session === false) {
        throw new RuntimeException('The selected session does not exist.');
    }

    $formationStmt = $pdo->prepare(
        'SELECT FormationID, SessionID, TeamID, HeadCoachID, Score FROM ' . qi('TeamFormation')
        . ' WHERE SessionID = :session ORDER BY FormationID'
    );
    $formationStmt->execute(['session' => $sessionId]);
    $formations = $formationStmt->fetchAll();
    if (count($formations) < 1 || count($formations) > 2) {
        throw new RuntimeException('This workflow supports sessions containing one or two team formations.');
    }

    return ['session' => $session, 'formations' => $formations];
}

function workflow_assert_session_time_has_no_conflicts(PDO $pdo, int $sessionId, string $newDateTime): void
{
    $stmt = $pdo->prepare(
        'SELECT ownFa.MembershipNumber, otherTf.FormationID, otherS.SessionDateTime'
        . ' FROM ' . qi('FormationAssignment') . ' ownFa'
        . ' JOIN ' . qi('TeamFormation') . ' ownTf ON ownTf.FormationID = ownFa.FormationID'
        . ' JOIN ' . qi('FormationAssignment') . ' otherFa ON otherFa.MembershipNumber = ownFa.MembershipNumber'
        . ' JOIN ' . qi('TeamFormation') . ' otherTf ON otherTf.FormationID = otherFa.FormationID'
        . ' JOIN ' . qi('Session') . ' otherS ON otherS.SessionID = otherTf.SessionID'
        . ' WHERE ownTf.SessionID = :session_id'
        . ' AND otherTf.SessionID <> :other_session_id'
        . ' AND DATE(otherS.SessionDateTime) = DATE(:new_datetime_date)'
        . ' AND ABS(TIMESTAMPDIFF(MINUTE, otherS.SessionDateTime, :new_datetime_diff)) < 180'
        . ' LIMIT 1'
    );
    $stmt->execute([
        'session_id' => $sessionId,
        'other_session_id' => $sessionId,
        'new_datetime_date' => $newDateTime,
        'new_datetime_diff' => $newDateTime,
    ]);
    $conflict = $stmt->fetch();
    if ($conflict !== false) {
        throw new RuntimeException(
            'Changing the session time would conflict with member #'
            . $conflict['MembershipNumber'] . ' in formation #'
            . $conflict['FormationID'] . ' at ' . $conflict['SessionDateTime'] . '.'
        );
    }
}

function workflow_assert_assignments_match_team(PDO $pdo, int $formationId, int $teamId): void
{
    $teamStmt = $pdo->prepare('SELECT LocationID, Gender FROM ' . qi('Team') . ' WHERE TeamID = :team');
    $teamStmt->execute(['team' => $teamId]);
    $team = $teamStmt->fetch();
    if ($team === false) {
        throw new RuntimeException('The selected team does not exist.');
    }

    $requiredGender = (string)$team['Gender'] === 'Boys' ? 'Boy' : 'Girl';
    $stmt = $pdo->prepare(
        'SELECT cm.MembershipNumber, cm.Gender, cml.LocationID'
        . ' FROM ' . qi('FormationAssignment') . ' fa'
        . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = fa.MembershipNumber'
        . ' LEFT JOIN ' . qi('ClubMemberLocation') . ' cml'
        . ' ON cml.MembershipNumber = cm.MembershipNumber AND cml.EndDate IS NULL'
        . ' WHERE fa.FormationID = :formation'
        . ' ORDER BY cml.StartDate DESC'
    );
    $stmt->execute(['formation' => $formationId]);
    foreach ($stmt->fetchAll() as $member) {
        if ($member['LocationID'] === null || (int)$member['LocationID'] !== (int)$team['LocationID']) {
            throw new RuntimeException(
                'Member #' . $member['MembershipNumber']
                . ' would not belong to the selected team location.'
            );
        }
        if ((string)$member['Gender'] !== $requiredGender) {
            throw new RuntimeException(
                'Member #' . $member['MembershipNumber']
                . ' would not match the selected team gender.'
            );
        }
    }
}

function workflow_map_session_formations(array $current, array $submitted): array
{
    $mapped = [];
    $usedSubmitted = [];

    foreach ($current as $currentIndex => $currentFormation) {
        foreach ($submitted as $submittedIndex => $submittedFormation) {
            if (isset($usedSubmitted[$submittedIndex])) {
                continue;
            }
            if ((int)$submittedFormation['team_id'] === (int)$currentFormation['TeamID']) {
                $mapped[$currentIndex] = $submittedFormation;
                $usedSubmitted[$submittedIndex] = true;
                break;
            }
        }
    }

    foreach ($current as $currentIndex => $currentFormation) {
        if (isset($mapped[$currentIndex])) {
            continue;
        }
        foreach ($submitted as $submittedIndex => $submittedFormation) {
            if (!isset($usedSubmitted[$submittedIndex])) {
                $mapped[$currentIndex] = $submittedFormation;
                $usedSubmitted[$submittedIndex] = true;
                break;
            }
        }
    }

    ksort($mapped);
    $unused = [];
    foreach ($submitted as $submittedIndex => $submittedFormation) {
        if (!isset($usedSubmitted[$submittedIndex])) {
            $unused[] = $submittedFormation;
        }
    }

    return [
        'mapped' => array_values($mapped),
        'unused' => $unused,
    ];
}

function handle_update_session_with_formations(PDO $pdo): void
{
    foreach (['Session', 'TeamFormation', 'Team', 'Personnel', 'FormationAssignment', 'ClubMember', 'ClubMemberLocation'] as $table) {
        assert_real_table($pdo, $table);
    }

    $sessionId = workflow_required_int($_POST['session_id'] ?? null, 'Session');
    $input = workflow_session_form_input();
    $bundle = workflow_session_bundle($pdo, $sessionId);
    $currentFormations = $bundle['formations'];
    $mapping = workflow_map_session_formations($currentFormations, $input['formations']);
    $mapped = $mapping['mapped'];
    $unused = $mapping['unused'];

    workflow_assert_session_time_has_no_conflicts($pdo, $sessionId, $input['session_datetime']);
    foreach ($currentFormations as $index => $currentFormation) {
        workflow_assert_assignments_match_team(
            $pdo,
            (int)$currentFormation['FormationID'],
            (int)$mapped[$index]['team_id']
        );
    }

    $pdo->beginTransaction();
    try {
        $sessionStmt = $pdo->prepare(
            'UPDATE ' . qi('Session')
            . ' SET SessionDateTime = :session_datetime, Address = :address, SessionType = :session_type'
            . ' WHERE SessionID = :session_id'
        );
        $sessionStmt->execute([
            'session_datetime' => $input['session_datetime'],
            'address' => $input['address'],
            'session_type' => $input['session_type'],
            'session_id' => $sessionId,
        ]);

        $formationStmt = $pdo->prepare(
            'UPDATE ' . qi('TeamFormation')
            . ' SET TeamID = :team_id, HeadCoachID = :coach_id, Score = :score'
            . ' WHERE FormationID = :formation_id AND SessionID = :session_id'
        );
        foreach ($currentFormations as $index => $currentFormation) {
            $formationStmt->execute([
                'team_id' => $mapped[$index]['team_id'],
                'coach_id' => $mapped[$index]['coach_id'],
                'score' => $mapped[$index]['score'],
                'formation_id' => $currentFormation['FormationID'],
                'session_id' => $sessionId,
            ]);
        }

        if (count($currentFormations) === 1) {
            if (count($unused) !== 1) {
                throw new RuntimeException('Could not determine the missing second team formation.');
            }
            $insertFormationStmt = $pdo->prepare(
                'INSERT INTO ' . qi('TeamFormation')
                . ' (SessionID, TeamID, HeadCoachID, Score)'
                . ' VALUES (:session_id, :team_id, :coach_id, :score)'
            );
            $insertFormationStmt->execute([
                'session_id' => $sessionId,
                'team_id' => $unused[0]['team_id'],
                'coach_id' => $unused[0]['coach_id'],
                'score' => $unused[0]['score'],
            ]);
        }

        $pdo->commit();
        flash(
            'success',
            count($currentFormations) === 1
                ? 'Session #' . $sessionId . ' was updated and its missing second formation was added.'
                : 'Session #' . $sessionId . ' and both formations were updated.'
        );
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'formations']);
}

function handle_delete_session_with_formations(PDO $pdo): void
{
    $sessionId = workflow_required_int($_POST['session_id'] ?? null, 'Session');
    $bundle = workflow_session_bundle($pdo, $sessionId);
    $formationIds = array_map(
        static fn(array $row): int => (int)$row['FormationID'],
        $bundle['formations']
    );

    $pdo->beginTransaction();
    try {
        $placeholders = implode(', ', array_fill(0, count($formationIds), '?'));
        $assignmentStmt = $pdo->prepare(
            'DELETE FROM ' . qi('FormationAssignment')
            . ' WHERE FormationID IN (' . $placeholders . ')'
        );
        $assignmentStmt->execute($formationIds);

        $formationStmt = $pdo->prepare('DELETE FROM ' . qi('TeamFormation') . ' WHERE SessionID = :session');
        $formationStmt->execute(['session' => $sessionId]);
        $sessionStmt = $pdo->prepare('DELETE FROM ' . qi('Session') . ' WHERE SessionID = :session');
        $sessionStmt->execute(['session' => $sessionId]);

        $pdo->commit();
        flash('success', 'Session #' . $sessionId . ', its formations, and their assignments were deleted.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'formations']);
}

function handle_assignment_update(PDO $pdo, bool $enforcePayment): void
{
    $oldFormationId = workflow_required_int($_POST['old_formation_id'] ?? null, 'Original formation');
    $oldMembershipNumber = workflow_required_int($_POST['old_membership_number'] ?? null, 'Original club member');
    $formationId = workflow_required_int($_POST['formation_id'] ?? null, 'Formation');
    $membershipNumber = workflow_required_int($_POST['membership_number'] ?? null, 'Club member');
    $role = trim((string)($_POST['role'] ?? ''));

    $existingStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . qi('FormationAssignment')
        . ' WHERE FormationID = :formation AND MembershipNumber = :member'
    );
    $existingStmt->execute([
        'formation' => $oldFormationId,
        'member' => $oldMembershipNumber,
    ]);
    if ((int)$existingStmt->fetchColumn() === 0) {
        throw new RuntimeException('The original assignment was not found.');
    }

    $roleColumn = table_columns($pdo, 'FormationAssignment')['Role'] ?? null;
    if (!$roleColumn) {
        throw new RuntimeException('FormationAssignment.Role is missing.');
    }
    $allowedRoles = enum_values((string)$roleColumn['Type']);
    if ($role === '' || ($allowedRoles !== [] && !in_array($role, $allowedRoles, true))) {
        throw new InvalidArgumentException('Select a valid formation role.');
    }

    $validation = validate_assignment(
        $pdo,
        $formationId,
        $membershipNumber,
        $enforcePayment,
        $oldFormationId,
        $oldMembershipNumber
    );

    $stmt = $pdo->prepare(
        'UPDATE ' . qi('FormationAssignment')
        . ' SET FormationID = :formation, MembershipNumber = :member, Role = :role'
        . ' WHERE FormationID = :old_formation AND MembershipNumber = :old_member'
    );
    $stmt->execute([
        'formation' => $formationId,
        'member' => $membershipNumber,
        'role' => $role,
        'old_formation' => $oldFormationId,
        'old_member' => $oldMembershipNumber,
    ]);

    if ($stmt->rowCount() === 0 && ($formationId !== $oldFormationId || $membershipNumber !== $oldMembershipNumber)) {
        throw new RuntimeException('The original assignment was not found.');
    }

    $message = 'Formation assignment updated successfully.';
    if (!$enforcePayment && is_array($validation['payment']) && !$validation['payment']['eligible']) {
        $message .= ' Payment warning: only $' . number_format($validation['payment']['paid'], 2)
            . ' of $' . number_format($validation['payment']['required'], 2)
            . ' is recorded for ' . $validation['payment']['year'] . '.';
    }
    flash('success', $message);
    redirect_to(['page' => 'assignments']);
}

function assignment_context(PDO $pdo, int $formationId, int $membershipNumber): array
{
    $formationStmt = $pdo->prepare(
        'SELECT tf.FormationID, tf.TeamID, t.LocationID AS TeamLocationID, t.Gender AS TeamGender,'
        . ' s.SessionDateTime, s.SessionType, t.TeamName'
        . ' FROM ' . qi('TeamFormation') . ' tf'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' WHERE tf.FormationID = :formation'
    );
    $formationStmt->execute(['formation' => $formationId]);
    $formation = $formationStmt->fetch();
    if ($formation === false) {
        throw new RuntimeException('The selected team formation does not exist.');
    }

    $memberStmt = $pdo->prepare(
        'SELECT cm.MembershipNumber, cm.FirstName, cm.LastName, cm.Gender, cm.DOB,'
        . ' cml.LocationID AS MemberLocationID'
        . ' FROM ' . qi('ClubMember') . ' cm'
        . ' LEFT JOIN ' . qi('ClubMemberLocation') . ' cml'
        . '   ON cml.MembershipNumber = cm.MembershipNumber AND cml.EndDate IS NULL'
        . ' WHERE cm.MembershipNumber = :member'
        . ' ORDER BY cml.StartDate DESC LIMIT 1'
    );
    $memberStmt->execute(['member' => $membershipNumber]);
    $member = $memberStmt->fetch();
    if ($member === false) {
        throw new RuntimeException('The selected club member does not exist.');
    }

    return ['formation' => $formation, 'member' => $member];
}

function validate_assignment(
    PDO $pdo,
    int $formationId,
    int $membershipNumber,
    bool $enforcePayment,
    ?int $ignoreFormationId = null,
    ?int $ignoreMembershipNumber = null
): array {
    foreach (['FormationAssignment', 'TeamFormation', 'Team', 'Session', 'ClubMember', 'ClubMemberLocation'] as $table) {
        assert_real_table($pdo, $table);
    }

    $context = assignment_context($pdo, $formationId, $membershipNumber);
    $formation = $context['formation'];
    $member = $context['member'];

    if ($member['MemberLocationID'] === null) {
        throw new RuntimeException('The member has no current location assignment.');
    }
    if ((int)$member['MemberLocationID'] !== (int)$formation['TeamLocationID']) {
        throw new RuntimeException('The member and team are not associated with the same current location.');
    }

    $requiredGender = $formation['TeamGender'] === 'Boys' ? 'Boy' : 'Girl';
    if ((string)$member['Gender'] !== $requiredGender) {
        throw new RuntimeException('The member gender does not match the selected team.');
    }

    $conflictSql =
        'SELECT tf.FormationID, t.TeamName, s.SessionDateTime'
        . ' FROM ' . qi('FormationAssignment') . ' fa'
        . ' JOIN ' . qi('TeamFormation') . ' tf ON tf.FormationID = fa.FormationID'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' WHERE fa.MembershipNumber = :member';
    $conflictParams = ['member' => $membershipNumber];

    if ($ignoreFormationId !== null && $ignoreMembershipNumber !== null) {
        $conflictSql .= ' AND NOT (fa.FormationID = :ignore_formation AND fa.MembershipNumber = :ignore_member)';
        $conflictParams['ignore_formation'] = $ignoreFormationId;
        $conflictParams['ignore_member'] = $ignoreMembershipNumber;
    }

    $conflictSql .=
        ' AND DATE(s.SessionDateTime) = DATE(:session_datetime)'
        . ' AND ABS(TIMESTAMPDIFF(MINUTE, s.SessionDateTime, :session_datetime_again)) < 180'
        . ' LIMIT 1';
    $conflictParams['session_datetime'] = $formation['SessionDateTime'];
    $conflictParams['session_datetime_again'] = $formation['SessionDateTime'];

    $conflictStmt = $pdo->prepare($conflictSql);
    $conflictStmt->execute($conflictParams);
    $conflict = $conflictStmt->fetch();
    if ($conflict !== false) {
        throw new RuntimeException(
            'Conflict: the member is already assigned to formation #'
            . $conflict['FormationID'] . ' (' . $conflict['TeamName'] . ') at '
            . $conflict['SessionDateTime'] . '. Assignments on the same day need at least three hours between start times.'
        );
    }

    $payment = null;
    if (table_exists($pdo, 'Payment')) {
        $payment = payment_status_for_session($pdo, $membershipNumber, (string)$formation['SessionDateTime']);
        if ($enforcePayment && !$payment['eligible']) {
            throw new RuntimeException(
                'The member is not payment-eligible for ' . $payment['year']
                . ': $' . number_format($payment['paid'], 2) . ' paid of $'
                . number_format($payment['required'], 2) . ' required.'
            );
        }
    }

    return ['context' => $context, 'payment' => $payment];
}

function handle_assignment_create(PDO $pdo, bool $enforcePayment): void
{
    $formationId = filter_var($_POST['formation_id'] ?? null, FILTER_VALIDATE_INT);
    $membershipNumber = filter_var($_POST['membership_number'] ?? null, FILTER_VALIDATE_INT);
    $role = trim((string)($_POST['role'] ?? ''));

    if ($formationId === false || $membershipNumber === false || $role === '') {
        throw new InvalidArgumentException('Select a formation, club member, and role.');
    }

    $roleColumn = table_columns($pdo, 'FormationAssignment')['Role'] ?? null;
    if (!$roleColumn) {
        throw new RuntimeException('FormationAssignment.Role is missing.');
    }
    $allowedRoles = enum_values((string)$roleColumn['Type']);
    if ($allowedRoles !== [] && !in_array($role, $allowedRoles, true)) {
        throw new InvalidArgumentException('Invalid formation role.');
    }

    $validation = validate_assignment($pdo, $formationId, $membershipNumber, $enforcePayment);

    $stmt = $pdo->prepare(
        'INSERT INTO ' . qi('FormationAssignment') . ' (FormationID, MembershipNumber, Role)'
        . ' VALUES (:formation, :member, :role)'
    );
    $stmt->execute([
        'formation' => $formationId,
        'member' => $membershipNumber,
        'role' => $role,
    ]);

    $message = 'Club member #' . $membershipNumber . ' was assigned successfully.';
    if (!$enforcePayment && is_array($validation['payment']) && !$validation['payment']['eligible']) {
        $message .= ' Payment warning: only $' . number_format($validation['payment']['paid'], 2)
            . ' of $' . number_format($validation['payment']['required'], 2)
            . ' is recorded for ' . $validation['payment']['year'] . '.';
    }
    flash('success', $message);
    redirect_to(['page' => 'assignments']);
}

function handle_assignment_delete(PDO $pdo): void
{
    $formationId = filter_var($_POST['formation_id'] ?? null, FILTER_VALIDATE_INT);
    $membershipNumber = filter_var($_POST['membership_number'] ?? null, FILTER_VALIDATE_INT);
    if ($formationId === false || $membershipNumber === false) {
        throw new InvalidArgumentException('Invalid assignment key.');
    }

    $stmt = $pdo->prepare(
        'DELETE FROM ' . qi('FormationAssignment')
        . ' WHERE FormationID = :formation AND MembershipNumber = :member'
    );
    $stmt->execute(['formation' => $formationId, 'member' => $membershipNumber]);
    flash('success', 'Formation assignment deleted.');
    redirect_to(['page' => 'assignments']);
}

function handle_payment_create(PDO $pdo): void
{
    foreach (['Payment', 'ClubMember'] as $table) {
        assert_real_table($pdo, $table);
    }

    $member = filter_var($_POST['membership_number'] ?? null, FILTER_VALIDATE_INT);
    $date = trim((string)($_POST['payment_date'] ?? ''));
    $amountRaw = trim((string)($_POST['amount'] ?? ''));
    $method = trim((string)($_POST['method'] ?? ''));
    $year = filter_var($_POST['membership_year'] ?? null, FILTER_VALIDATE_INT);
    $installment = filter_var($_POST['installment_number'] ?? null, FILTER_VALIDATE_INT);

    if ($member === false || $year === false || $installment === false) {
        throw new InvalidArgumentException('Member, membership year, and installment number are required.');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Enter a valid payment date.');
    }
    if (!is_numeric($amountRaw) || (float)$amountRaw <= 0) {
        throw new InvalidArgumentException('Payment amount must be greater than zero.');
    }
    if (!in_array($method, ['Cash', 'Debit', 'Credit card'], true)) {
        throw new InvalidArgumentException('Select a valid payment method.');
    }
    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('Membership year is outside the expected range.');
    }
    if ($installment < 1 || $installment > 4) {
        throw new InvalidArgumentException('Installment number must be between 1 and 4.');
    }

    $duplicateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . qi('Payment')
        . ' WHERE MembershipNumber = :member AND MembershipYear = :year AND InstallmentNumber = :installment'
    );
    $duplicateStmt->execute(['member' => $member, 'year' => $year, 'installment' => $installment]);
    if ((int)$duplicateStmt->fetchColumn() > 0) {
        throw new RuntimeException('That member already has the selected installment number for the membership year.');
    }

    $paymentColumns = table_columns($pdo, 'Payment');
    $insertColumns = ['MembershipNumber', 'PaymentDate', 'Amount', 'Method', 'MembershipYear', 'InstallmentNumber'];
    $params = [
        'member' => $member,
        'payment_date' => $date,
        'amount' => $amountRaw,
        'method' => $method,
        'membership_year' => $year,
        'installment' => $installment,
    ];

    $columnSql = [
        qi('MembershipNumber'), qi('PaymentDate'), qi('Amount'), qi('Method'), qi('MembershipYear'), qi('InstallmentNumber'),
    ];
    $valueSql = [
        ':member', ':payment_date', ':amount', ':method', ':membership_year', ':installment',
    ];

    if (isset($paymentColumns['PaymentID']) && !is_auto_increment($paymentColumns['PaymentID'])) {
        $nextId = (int)$pdo->query('SELECT COALESCE(MAX(PaymentID), 0) + 1 FROM ' . qi('Payment'))->fetchColumn();
        array_unshift($columnSql, qi('PaymentID'));
        array_unshift($valueSql, ':payment_id');
        $params['payment_id'] = $nextId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ' . qi('Payment') . ' (' . implode(', ', $columnSql) . ')'
        . ' VALUES (' . implode(', ', $valueSql) . ')'
    );
    $stmt->execute($params);

    flash('success', 'Payment recorded for club member #' . $member . '.');
    redirect_to(['page' => 'payments']);
}

function trigger_sql_statements(): array
{
    $insertTrigger = <<<'SQL'
CREATE TRIGGER trg_formation_assignment_conflict_bi
BEFORE INSERT ON FormationAssignment
FOR EACH ROW
BEGIN
    DECLARE new_start DATETIME;
    DECLARE conflict_count INT DEFAULT 0;

    SELECT s.SessionDateTime
      INTO new_start
      FROM TeamFormation tf
      JOIN `Session` s ON s.SessionID = tf.SessionID
     WHERE tf.FormationID = NEW.FormationID;

    SELECT COUNT(*)
      INTO conflict_count
      FROM FormationAssignment fa
      JOIN TeamFormation tf ON tf.FormationID = fa.FormationID
      JOIN `Session` s ON s.SessionID = tf.SessionID
     WHERE fa.MembershipNumber = NEW.MembershipNumber
       AND tf.FormationID <> NEW.FormationID
       AND DATE(s.SessionDateTime) = DATE(new_start)
       AND ABS(TIMESTAMPDIFF(MINUTE, s.SessionDateTime, new_start)) < 180;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Assignment rejected: formations on the same day must be at least 3 hours apart.';
    END IF;
END
SQL;

    $updateTrigger = <<<'SQL'
CREATE TRIGGER trg_formation_assignment_conflict_bu
BEFORE UPDATE ON FormationAssignment
FOR EACH ROW
BEGIN
    DECLARE new_start DATETIME;
    DECLARE conflict_count INT DEFAULT 0;

    SELECT s.SessionDateTime
      INTO new_start
      FROM TeamFormation tf
      JOIN `Session` s ON s.SessionID = tf.SessionID
     WHERE tf.FormationID = NEW.FormationID;

    SELECT COUNT(*)
      INTO conflict_count
      FROM FormationAssignment fa
      JOIN TeamFormation tf ON tf.FormationID = fa.FormationID
      JOIN `Session` s ON s.SessionID = tf.SessionID
     WHERE fa.MembershipNumber = NEW.MembershipNumber
       AND NOT (
           fa.FormationID = OLD.FormationID
           AND fa.MembershipNumber = OLD.MembershipNumber
       )
       AND DATE(s.SessionDateTime) = DATE(new_start)
       AND ABS(TIMESTAMPDIFF(MINUTE, s.SessionDateTime, new_start)) < 180;

    IF conflict_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Assignment rejected: formations on the same day must be at least 3 hours apart.';
    END IF;
END
SQL;

    return [
        'trg_formation_assignment_conflict_bi' => $insertTrigger,
        'trg_formation_assignment_conflict_bu' => $updateTrigger,
    ];
}

function handle_install_triggers(PDO $pdo): void
{
    foreach (['FormationAssignment', 'TeamFormation', 'Session'] as $table) {
        assert_real_table($pdo, $table);
    }

    foreach (trigger_sql_statements() as $name => $sql) {
        $pdo->exec('DROP TRIGGER IF EXISTS ' . qi($name));
        $pdo->exec($sql);
    }

    flash('success', 'Scheduling protection is now active.');
    redirect_to(['page' => 'integrity']);
}

function handle_integrity_test(PDO $pdo): void
{
    foreach (['FormationAssignment', 'TeamFormation', 'Session'] as $table) {
        assert_real_table($pdo, $table);
    }

    $source = $pdo->query(
        'SELECT fa.MembershipNumber, fa.Role, tf.TeamID, tf.HeadCoachID,'
        . ' s.SessionDateTime, s.Address, s.SessionType'
        . ' FROM ' . qi('FormationAssignment') . ' fa'
        . ' JOIN ' . qi('TeamFormation') . ' tf ON tf.FormationID = fa.FormationID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' ORDER BY s.SessionDateTime, fa.MembershipNumber LIMIT 1'
    )->fetch();

    if ($source === false) {
        throw new RuntimeException('Create at least one formation assignment before running the automatic integrity test.');
    }

    $original = new DateTimeImmutable((string)$source['SessionDateTime']);
    $testDateTime = $original->modify('+1 hour')->format('Y-m-d H:i:s');
    if ($original->format('Y-m-d') !== substr($testDateTime, 0, 10)) {
        $testDateTime = $original->modify('-1 hour')->format('Y-m-d H:i:s');
    }

    $pdo->beginTransaction();
    try {
        $sessionStmt = $pdo->prepare(
            'INSERT INTO ' . qi('Session') . ' (SessionDateTime, Address, SessionType)'
            . ' VALUES (:datetime, :address, :type)'
        );
        $sessionStmt->execute([
            'datetime' => $testDateTime,
            'address' => (string)$source['Address'] . ' — automatic conflict test',
            'type' => (string)$source['SessionType'],
        ]);
        $sessionId = (int)$pdo->lastInsertId();

        $formationStmt = $pdo->prepare(
            'INSERT INTO ' . qi('TeamFormation') . ' (SessionID, TeamID, HeadCoachID, Score)'
            . ' VALUES (:session, :team, :coach, NULL)'
        );
        $formationStmt->execute([
            'session' => $sessionId,
            'team' => $source['TeamID'],
            'coach' => $source['HeadCoachID'],
        ]);
        $formationId = (int)$pdo->lastInsertId();

        try {
            $assignmentStmt = $pdo->prepare(
                'INSERT INTO ' . qi('FormationAssignment') . ' (FormationID, MembershipNumber, Role)'
                . ' VALUES (:formation, :member, :role)'
            );
            $assignmentStmt->execute([
                'formation' => $formationId,
                'member' => $source['MembershipNumber'],
                'role' => $source['Role'],
            ]);

            $pdo->rollBack();
            flash(
                'error',
                'Test failed: the conflicting assignment was accepted. Refresh the scheduling rule and try again. Temporary test data was removed.'
            );
        } catch (PDOException $exception) {
            $pdo->rollBack();
            $message = $exception->getMessage();
            if (str_contains($message, 'at least 3 hours') || str_contains($message, 'formations on the same day')) {
                flash(
                    'success',
                    'Test passed: the database rejected an assignment only one hour apart. Temporary test data was removed.'
                );
            } else {
                throw $exception;
            }
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    redirect_to(['page' => 'integrity']);
}

function handle_generate_emails(PDO $pdo): void
{
    foreach (['EmailLog', 'FormationAssignment', 'TeamFormation', 'Session', 'Team', 'Location', 'Personnel', 'ClubMember'] as $table) {
        assert_real_table($pdo, $table);
    }

    $startDate = trim((string)($_POST['start_date'] ?? ''));
    $endDate = trim((string)($_POST['end_date'] ?? ''));
    $skipDuplicates = isset($_POST['skip_duplicates']);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        throw new InvalidArgumentException('Enter valid start and end dates.');
    }
    if ($endDate < $startDate) {
        throw new InvalidArgumentException('The end date cannot be before the start date.');
    }

    $locationNameColumn = first_existing_column($pdo, 'Location', ['LocationName', 'Name']);
    $locationExpression = $locationNameColumn
        ? 'l.' . qi($locationNameColumn)
        : "CONCAT('Location #', l.LocationID)";

    $query =
        'SELECT fa.MembershipNumber, fa.Role,'
        . ' cm.FirstName AS MemberFirstName, cm.LastName AS MemberLastName, cm.Email AS MemberEmail,'
        . ' s.SessionDateTime, s.Address, s.SessionType,'
        . ' t.TeamName, t.LocationID,'
        . ' p.FirstName AS CoachFirstName, p.LastName AS CoachLastName, p.Email AS CoachEmail,'
        . ' ' . $locationExpression . ' AS LocationName'
        . ' FROM ' . qi('FormationAssignment') . ' fa'
        . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = fa.MembershipNumber'
        . ' JOIN ' . qi('TeamFormation') . ' tf ON tf.FormationID = fa.FormationID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Personnel') . ' p ON p.PersonnelID = tf.HeadCoachID'
        . ' JOIN ' . qi('Location') . ' l ON l.LocationID = t.LocationID'
        . ' WHERE s.SessionDateTime >= :start_datetime'
        . '   AND s.SessionDateTime < DATE_ADD(:end_date, INTERVAL 1 DAY)'
        . ' ORDER BY s.SessionDateTime, t.TeamName, cm.LastName, cm.FirstName';

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'start_datetime' => $startDate . ' 00:00:00',
        'end_date' => $endDate,
    ]);
    $scheduled = $stmt->fetchAll();

    $duplicateStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . qi('EmailLog')
        . ' WHERE SenderLocationID = :location AND ReceiverMembershipNumber = :member AND Subject = :subject'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO ' . qi('EmailLog')
        . ' (EmailDate, SenderLocationID, ReceiverMembershipNumber, Subject, BodyExcerpt)'
        . ' VALUES (NOW(), :location, :member, :subject, :excerpt)'
    );

    $generated = [];
    $inserted = 0;
    $skipped = 0;

    $pdo->beginTransaction();
    try {
        foreach ($scheduled as $row) {
            $sessionDate = new DateTimeImmutable((string)$row['SessionDateTime']);
            $nature = strtolower((string)$row['SessionType']);
            $subject = (string)$row['TeamName'] . ' '
                . $sessionDate->format('l j-F-Y g:i A') . ' '
                . $nature . ' session';

            $body = sprintf(
                '%s %s is assigned as %s. Head coach: %s %s (%s). This is a %s session at %s.',
                $row['MemberFirstName'],
                $row['MemberLastName'],
                $row['Role'],
                $row['CoachFirstName'],
                $row['CoachLastName'],
                $row['CoachEmail'],
                $nature,
                $row['Address']
            );

            if ($skipDuplicates) {
                $duplicateStmt->execute([
                    'location' => $row['LocationID'],
                    'member' => $row['MembershipNumber'],
                    'subject' => $subject,
                ]);
                if ((int)$duplicateStmt->fetchColumn() > 0) {
                    $skipped++;
                    continue;
                }
            }

            $insertStmt->execute([
                'location' => $row['LocationID'],
                'member' => $row['MembershipNumber'],
                'subject' => $subject,
                'excerpt' => truncate_text($body, 100),
            ]);
            $inserted++;

            if (count($generated) < 100) {
                $generated[] = [
                    'sender' => $row['LocationName'],
                    'receiver' => $row['MemberFirstName'] . ' ' . $row['MemberLastName'] . ' <' . $row['MemberEmail'] . '>',
                    'subject' => $subject,
                    'body' => $body,
                ];
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $_SESSION['generated_email_preview'] = $generated;
    flash(
        'success',
        $inserted . ' email log entr' . ($inserted === 1 ? 'y was' : 'ies were')
        . ' generated. ' . $skipped . ' probable duplicate(s) were skipped. No SMTP message was sent.'
    );
    redirect_to(['page' => 'emails']);
}

function validate_read_only_sql(string $sql): string
{
    $sql = trim($sql);
    $sql = preg_replace('/;\s*$/', '', $sql) ?? $sql;
    if ($sql === '') {
        throw new InvalidArgumentException('Enter a SQL query.');
    }
    if (str_contains($sql, ';')) {
        throw new InvalidArgumentException('Only one SQL statement can be executed at a time.');
    }

    $withoutLeadingComments = preg_replace('/\A(?:\s|--[^\n]*\n|#[^\n]*\n|\/\*.*?\*\/)+/s', '', $sql) ?? $sql;
    if (!preg_match('/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $withoutLeadingComments)) {
        throw new InvalidArgumentException('The report runner only accepts read-only SELECT/SHOW/DESCRIBE/EXPLAIN queries.');
    }

    $dangerous = '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|REVOKE|CALL|SET|LOAD|OUTFILE|DUMPFILE)\b/i';
    if (preg_match($dangerous, $withoutLeadingComments)) {
        throw new InvalidArgumentException('A modifying or file-writing SQL keyword was detected.');
    }

    return $sql;
}


$reportSql = '';
$reportRows = [];
$reportColumns = [];
$reportError = null;

if (is_post()) {
    try {
        verify_csrf();
        $db = require_database($pdo);
        $action = (string)($_POST['action'] ?? '');

        switch ($action) {
            case 'crud_save':
                handle_crud_save($db);
                break;
            case 'crud_delete':
                handle_crud_delete($db);
                break;
            case 'personnel_save':
                handle_personnel_save($db);
                break;
            case 'personnel_delete':
                handle_personnel_delete($db);
                break;
            case 'worksat_save':
                handle_worksat_save($db);
                break;
            case 'worksat_delete':
                handle_worksat_delete($db);
                break;
            case 'family_member_save':
                handle_family_member_save($db);
                break;
            case 'family_member_delete':
                handle_family_member_delete($db);
                break;
            case 'family_location_save':
                handle_family_location_save($db);
                break;
            case 'family_location_delete':
                handle_family_location_delete($db);
                break;
            case 'club_member_save':
                handle_club_member_save($db);
                break;
            case 'club_member_delete':
                handle_club_member_delete($db);
                break;
            case 'club_location_save':
                handle_club_location_save($db);
                break;
            case 'club_location_delete':
                handle_club_location_delete($db);
                break;
            case 'guardianship_save':
                handle_guardianship_save($db);
                break;
            case 'guardianship_delete':
                handle_guardianship_delete($db);
                break;
            case 'create_session_formations':
                handle_create_session_with_formations($db);
                break;
            case 'update_session_formations':
                handle_update_session_with_formations($db);
                break;
            case 'delete_session_formations':
                handle_delete_session_with_formations($db);
                break;
            case 'assignment_create':
                handle_assignment_create($db, (bool)$APP['enforce_payment_eligibility_on_assignment']);
                break;
            case 'assignment_update':
                handle_assignment_update($db, (bool)$APP['enforce_payment_eligibility_on_assignment']);
                break;
            case 'assignment_delete':
                handle_assignment_delete($db);
                break;
            case 'payment_create':
                handle_payment_create($db);
                break;
            case 'install_triggers':
                handle_install_triggers($db);
                break;
            case 'integrity_test':
                handle_integrity_test($db);
                break;
            case 'generate_emails':
                handle_generate_emails($db);
                break;
            case 'run_report':
                $reportSql = validate_read_only_sql((string)($_POST['report_sql'] ?? ''));
                $stmt = $db->query($reportSql);
                $reportRows = $stmt->fetchAll();
                if ($stmt->columnCount() > 0) {
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $metadata = $stmt->getColumnMeta($i);
                        $reportColumns[] = (string)($metadata['name'] ?? ('Column ' . ($i + 1)));
                    }
                }
                if (count($reportRows) > 500) {
                    $reportRows = array_slice($reportRows, 0, 500);
                }
                break;
            default:
                throw new InvalidArgumentException('Unknown form action.');
        }
    } catch (Throwable $exception) {
        if ((string)($_POST['action'] ?? '') === 'run_report') {
            $reportError = $exception->getMessage();
            $reportSql = (string)($_POST['report_sql'] ?? '');
        } else {
            flash('error', $exception->getMessage());
            $fallbackPage = (string)($_POST['return_page'] ?? 'dashboard');
            $params = ['page' => $fallbackPage];
            if (isset($_POST['table'])) {
                $params['table'] = (string)$_POST['table'];
            }
            redirect_to($params);
        }
    }
}


function nav_link(string $label, array $params, string $currentPage, ?string $currentTable = null): string
{
    $active = ($params['page'] ?? 'dashboard') === $currentPage;
    if (($params['page'] ?? '') === 'table' && isset($params['table'])) {
        $active = $active && $params['table'] === $currentTable;
    }

    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . e(build_url($params)) . '">'
        . e($label) . '</a>';
}

function render_page_header(string $title, string $currentPage, ?string $currentTable, ?PDO $pdo, array $dbConfig): void
{
    global $APP;
    $connected = $pdo instanceof PDO;
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> — <?= e($APP['title']) ?></title>
    <style>
        :root {
            --bg: #f3f6f8;
            --surface: #ffffff;
            --surface-soft: #eef4f1;
            --text: #17231d;
            --muted: #64736b;
            --primary: #17633e;
            --primary-dark: #0f4b2e;
            --primary-soft: #dff1e7;
            --danger: #a12c2c;
            --danger-soft: #fde7e7;
            --warning: #7b5a00;
            --warning-soft: #fff3c4;
            --border: #d7e0db;
            --shadow: 0 10px 30px rgba(25, 52, 38, 0.08);
            --radius: 14px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
        }
        a { color: var(--primary); }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: rgba(255,255,255,0.97);
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(10px);
        }
        .topbar-inner {
            max-width: 1500px;
            margin: 0 auto;
            padding: 12px 22px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .brand { min-width: 220px; }
        .brand strong { display: block; font-size: 1.02rem; }
        .brand span { color: var(--muted); font-size: .78rem; }
        .nav {
            display: flex;
            gap: 5px;
            align-items: center;
            flex-wrap: wrap;
            flex: 1;
        }
        .nav-link {
            text-decoration: none;
            color: #33483d;
            padding: 8px 10px;
            border-radius: 9px;
            font-size: .87rem;
            font-weight: 650;
        }
        .nav-link:hover { background: var(--surface-soft); }
        .nav-link.active { color: #fff; background: var(--primary); }
        .connection-pill {
            white-space: nowrap;
            border-radius: 999px;
            padding: 7px 10px;
            font-size: .76rem;
            font-weight: 750;
            background: <?= $connected ? 'var(--primary-soft)' : 'var(--danger-soft)' ?>;
            color: <?= $connected ? 'var(--primary-dark)' : 'var(--danger)' ?>;
        }
        main { max-width: 1500px; margin: 0 auto; padding: 26px 22px 50px; }
        .page-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 18px; }
        .page-heading h1 { font-size: clamp(1.55rem, 3vw, 2.25rem); margin: 0 0 4px; line-height: 1.15; }
        .page-heading p { margin: 0; color: var(--muted); max-width: 850px; }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        .card h2, .card h3 { margin-top: 0; }
        .card-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-bottom: 14px; }
        .card-header h2, .card-header h3 { margin: 0; }
        .grid { display: grid; gap: 18px; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .grid-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .metric { padding: 18px; background: linear-gradient(145deg, #fff, #f1f7f3); border: 1px solid var(--border); border-radius: 13px; }
        .metric .value { font-size: 2rem; font-weight: 800; line-height: 1; margin-bottom: 7px; }
        .metric .label { color: var(--muted); font-weight: 650; }
        .muted { color: var(--muted); }
        .small { font-size: .84rem; }
        .notice { padding: 13px 15px; border-radius: 11px; margin-bottom: 14px; border: 1px solid transparent; }
        .notice.success { background: var(--primary-soft); color: var(--primary-dark); border-color: #b8ddc8; }
        .notice.error { background: var(--danger-soft); color: var(--danger); border-color: #f4bcbc; }
        .notice.warning { background: var(--warning-soft); color: var(--warning); border-color: #efda8b; }
        .toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .button, button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 38px;
            border-radius: 9px;
            border: 1px solid var(--primary);
            padding: 8px 13px;
            background: var(--primary);
            color: #fff;
            text-decoration: none;
            font: inherit;
            font-size: .88rem;
            font-weight: 750;
            cursor: pointer;
        }
        .button:hover, button:hover { background: var(--primary-dark); }
        .button.secondary, button.secondary { color: var(--primary-dark); background: #fff; border-color: #9fc6b0; }
        .button.secondary:hover, button.secondary:hover { background: var(--primary-soft); }
        .button.danger, button.danger { background: #fff; color: var(--danger); border-color: #d99999; }
        .button.danger:hover, button.danger:hover { background: var(--danger-soft); }
        .button.small-button, button.small-button { min-height: 31px; padding: 5px 9px; font-size: .78rem; }
        form.inline { display: inline; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px 18px; }
        .form-grid.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field.full { grid-column: 1 / -1; }
        .field label { font-weight: 700; font-size: .86rem; }
        .field .hint { color: var(--muted); font-size: .75rem; }
        input, select, textarea {
            width: 100%;
            min-height: 41px;
            padding: 9px 10px;
            border: 1px solid #bdcbc3;
            border-radius: 9px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: 3px solid rgba(23,99,62,.14); border-color: var(--primary); }
        input[readonly] { background: #edf1ef; color: #536159; }
        textarea { min-height: 110px; resize: vertical; }
        textarea.sql { min-height: 260px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .84rem; }
        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 11px; }
        table { width: 100%; border-collapse: collapse; min-width: 720px; background: #fff; }
        th, td { border-bottom: 1px solid var(--border); padding: 10px 11px; text-align: left; vertical-align: top; font-size: .82rem; }
        th { position: sticky; top: 0; z-index: 1; background: #edf4f0; color: #294438; text-transform: none; white-space: nowrap; }
        tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: #f8fbf9; }
        td.actions { white-space: nowrap; }
        .badge { display: inline-block; border-radius: 999px; padding: 3px 8px; background: var(--surface-soft); font-size: .72rem; font-weight: 750; }
        .badge.success { background: var(--primary-soft); color: var(--primary-dark); }
        .badge.warning { background: var(--warning-soft); color: var(--warning); }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        pre { overflow: auto; padding: 15px; background: #102219; color: #d9f5e5; border-radius: 10px; font-size: .8rem; white-space: pre-wrap; }
        details { border: 1px solid var(--border); border-radius: 10px; padding: 11px 13px; background: #fbfdfc; }
        details + details { margin-top: 9px; }
        summary { cursor: pointer; font-weight: 750; }
        .split { display: grid; grid-template-columns: minmax(300px, .82fr) minmax(500px, 1.55fr); gap: 20px; align-items: start; }
        .sticky-card { position: sticky; top: 90px; }
        .empty { padding: 28px; text-align: center; color: var(--muted); }
        footer { max-width: 1500px; margin: 0 auto; padding: 0 22px 35px; color: var(--muted); font-size: .8rem; }
        @media (max-width: 1050px) {
            .topbar-inner { align-items: flex-start; flex-direction: column; }
            .brand { min-width: 0; }
            .connection-pill { position: absolute; top: 14px; right: 18px; }
            .split { grid-template-columns: 1fr; }
            .sticky-card { position: static; }
            .grid-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 720px) {
            main { padding: 20px 13px 40px; }
            .topbar-inner { padding: 11px 13px; }
            .grid-2, .grid-3, .grid-4, .form-grid, .form-grid.three { grid-template-columns: 1fr; }
            .page-heading { flex-direction: column; }
            .connection-pill { display: none; }
            .card { padding: 15px; }
        }
    </style>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <strong>Country Soccer Club System</strong>
            <span>COMP 353 · Main Project</span>
        </div>
        <nav class="nav" aria-label="Primary navigation">
            <?= nav_link('Dashboard', ['page' => 'dashboard'], $currentPage, $currentTable) ?>
            <?= nav_link('Locations', ['page' => 'table', 'table' => 'Location'], $currentPage, $currentTable) ?>
            <?= nav_link('Personnel', ['page' => 'personnel'], $currentPage, $currentTable) ?>
            <?= nav_link('Family Members', ['page' => 'family_members'], $currentPage, $currentTable) ?>
            <?= nav_link('Club Members', ['page' => 'club_members'], $currentPage, $currentTable) ?>
            <?= nav_link('Formations', ['page' => 'formations'], $currentPage, $currentTable) ?>
            <?= nav_link('Assignments', ['page' => 'assignments'], $currentPage, $currentTable) ?>
            <?= nav_link('Payments', ['page' => 'payments'], $currentPage, $currentTable) ?>
            <?= nav_link('Reports', ['page' => 'reports'], $currentPage, $currentTable) ?>
            <?= nav_link('Emails', ['page' => 'emails'], $currentPage, $currentTable) ?>
            <?= nav_link('Scheduling Rules', ['page' => 'integrity'], $currentPage, $currentTable) ?>
        </nav>
        <div class="connection-pill">
            <?= $connected ? 'Connected · ' . e($dbConfig['name']) : 'Database offline' ?>
        </div>
    </div>
</header>
<main>
<?php
}

function render_page_footer(): void
{
    ?>
</main>
<footer>
    COMP 353 · Main Project
</footer>
<script>
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm') || 'Continue?')) {
                event.preventDefault();
            }
        });
    });
</script>
</body>
</html>
<?php
}

function render_flashes(array $flashes): void
{
    foreach ($flashes as $flash) {
        $type = in_array($flash['type'] ?? '', ['success', 'error', 'warning'], true) ? $flash['type'] : 'warning';
        echo '<div class="notice ' . e($type) . '">' . e((string)($flash['message'] ?? '')) . '</div>';
    }
}

function render_connection_error(string $message): void
{
    ?>
<div class="page-heading">
    <div>
        <h1>Database connection required</h1>
        <p>The GUI file loaded, but PDO could not connect to MySQL.</p>
    </div>
</div>
<div class="card">
    <div class="notice error"><strong>Connection error:</strong> <?= e($message) ?></div>
    <h2>Update the configuration</h2>
    <p>Edit the <code>$DB</code> array near the top of <code>index.php</code>, or set the corresponding environment variables.</p>
    <pre>$DB = [
    'host' =&gt; 'YOUR_AITS_HOST',
    'port' =&gt; '3306',
    'name' =&gt; 'wdc353_1',
    'user' =&gt; 'YOUR_USERNAME',
    'pass' =&gt; 'YOUR_PASSWORD',
];</pre>
    <p class="muted small">Do not commit the real database password to a public repository.</p>
</div>
<?php
}

function page_heading(string $title, string $description, string $actions = ''): void
{
    echo '<div class="page-heading"><div><h1>' . e($title) . '</h1><p>' . e($description) . '</p></div>' . $actions . '</div>';
}

function render_dashboard(PDO $pdo, array $dbConfig): void
{
    page_heading(
        'Dashboard',
        'Quick access to the required CRUD workflows, formation assignments, payments, reports, conflict trigger, and generated-email logs.'
    );

    $metricTables = [
        'Location' => 'Locations',
        'Personnel' => 'Personnel',
        'FamilyMember' => 'Family members',
        'ClubMember' => 'Club members',
        'TeamFormation' => 'Team formations',
        'Payment' => 'Payments',
        'EmailLog' => 'Email logs',
    ];
    ?>
<div class="grid grid-4">
    <?php foreach ($metricTables as $table => $label): ?>
        <div class="metric">
            <div class="value"><?= table_exists($pdo, $table) ? number_format(table_row_count($pdo, $table)) : '—' ?></div>
            <div class="label"><?= e($label) ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div style="margin-top:20px">
    <section class="card">
        <h2>Quick Actions</h2>
        <div class="grid grid-2">
            <a class="button secondary" href="<?= e(build_url(['page' => 'formations'])) ?>">Create session + formations</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'assignments'])) ?>">Assign a club member</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'payments'])) ?>">Record a payment</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'integrity'])) ?>">Install/test trigger</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'emails'])) ?>">Generate email logs</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'reports'])) ?>">Run Q8–Q19 SQL</a>
        </div>
    </section>
</div>

<section class="card">
    <h2>Implementation status</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Required Actions</th><th>Table route</th><th>Status</th></tr></thead>
            <tbody>
            <?php
            $requirements = [
                ['Location create/edit/delete/display', 'Locations', table_exists($pdo, 'Location')],
                ['Personnel create/edit/delete/display', 'Personnel', table_exists($pdo, 'Personnel')],
                ['FamilyMember create/edit/delete/display', 'Family Members', table_exists($pdo, 'FamilyMember')],
                ['ClubMember create/edit/delete/display', 'Club Members', table_exists($pdo, 'ClubMember')],
                ['TeamFormation create/edit/delete/display', 'Formations', table_exists($pdo, 'TeamFormation')],
                ['Assign/delete club member in formation', 'Assignments', table_exists($pdo, 'FormationAssignment')],
                ['Record club-member payment', 'Payments', table_exists($pdo, 'Payment')],
                ['Three-hour conflict trigger + test', 'Integrity', table_exists($pdo, 'FormationAssignment')],
                ['Generate emails + EmailLog display', 'Emails', table_exists($pdo, 'EmailLog')],
                ['Display query results', 'Reports', true],
            ];
            foreach ($requirements as [$requirement, $route, $ready]):
            ?>
                <tr>
                    <td><?= e($requirement) ?></td>
                    <td><?= e($route) ?></td>
                    <td><span class="badge <?= $ready ? 'success' : 'warning' ?>"><?= $ready ? 'Available' : 'Missing table' ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
}

function render_generic_field(PDO $pdo, string $table, string $columnName, array $column, $value, bool $editing, array $pkColumns): void
{
    $type = strtolower((string)$column['Type']);
    $nullable = is_nullable($column);
    $isPk = in_array($columnName, $pkColumns, true);
    $readonly = $editing && $isPk;
    $foreign = foreign_keys($pdo, $table)[$columnName] ?? null;
    $inputName = 'field[' . $columnName . ']';
    $htmlValue = $value;

    if ((str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) && is_string($htmlValue) && $htmlValue !== '') {
        $htmlValue = str_replace(' ', 'T', substr($htmlValue, 0, 16));
    }

    echo '<div class="field' . (str_contains($type, 'text') ? ' full' : '') . '">';
    echo '<label for="field_' . e($columnName) . '">' . e(humanize($columnName));
    if (!$nullable && !is_auto_increment($column)) {
        echo ' <span aria-label="required">*</span>';
    }
    echo '</label>';

    if ($foreign && !$readonly) {
        $options = reference_options($pdo, $foreign['table'], $foreign['column']);
        echo '<select id="field_' . e($columnName) . '" name="' . e($inputName) . '">';
        if ($nullable) {
            echo '<option value="">— None —</option>';
        } else {
            echo '<option value="">— Select —</option>';
        }
        foreach ($options as $option) {
            $selected = (string)$htmlValue === (string)$option['option_value'] ? ' selected' : '';
            echo '<option value="' . e($option['option_value']) . '"' . $selected . '>' . e($option['option_label']) . '</option>';
        }
        echo '</select>';
    } elseif (($enum = enum_values($type)) !== [] && !$readonly) {
        echo '<select id="field_' . e($columnName) . '" name="' . e($inputName) . '">';
        if ($nullable) {
            echo '<option value="">— None —</option>';
        } else {
            echo '<option value="">— Select —</option>';
        }
        foreach ($enum as $option) {
            $selected = (string)$htmlValue === $option ? ' selected' : '';
            echo '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
        }
        echo '</select>';
    } elseif (preg_match('/tinyint\(1\)|boolean|bool/', $type) && !$readonly) {
        echo '<select id="field_' . e($columnName) . '" name="' . e($inputName) . '">';
        if ($nullable) {
            echo '<option value="">— None —</option>';
        }
        echo '<option value="1"' . ((string)$htmlValue === '1' ? ' selected' : '') . '>Yes / True</option>';
        echo '<option value="0"' . ((string)$htmlValue === '0' ? ' selected' : '') . '>No / False</option>';
        echo '</select>';
    } elseif (str_contains($type, 'text') || str_contains($type, 'blob')) {
        echo '<textarea id="field_' . e($columnName) . '" name="' . e($inputName) . '"' . ($readonly ? ' readonly' : '') . '>' . e($htmlValue) . '</textarea>';
    } else {
        $inputType = 'text';
        $extra = '';
        if (str_starts_with($type, 'date')) {
            $inputType = 'date';
        } elseif (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
            $inputType = 'datetime-local';
        } elseif (preg_match('/\b(tinyint|smallint|mediumint|int|bigint)\b/', $type)) {
            $inputType = 'number';
            $extra = ' step="1"';
        } elseif (preg_match('/\b(decimal|numeric|float|double|real)\b/', $type)) {
            $inputType = 'number';
            $extra = ' step="any"';
        } elseif (str_contains(strtolower($columnName), 'email')) {
            $inputType = 'email';
        } elseif (str_contains(strtolower($columnName), 'phone')) {
            $inputType = 'tel';
        } elseif (str_contains(strtolower($columnName), 'web') || str_contains(strtolower($columnName), 'url')) {
            $inputType = 'url';
        }
        echo '<input id="field_' . e($columnName) . '" type="' . $inputType . '" name="' . e($inputName) . '" value="' . e($htmlValue) . '"' . $extra . ($readonly ? ' readonly' : '') . '>';
    }

    $hint = (string)$column['Type'];
    if ($foreign) {
        $hint .= ' · references ' . $foreign['table'] . '.' . $foreign['column'];
    }
    if (is_auto_increment($column)) {
        $hint .= ' · auto increment';
    }
    echo '<span class="hint">' . e($hint) . '</span>';
    echo '</div>';
}

function render_table_page(PDO $pdo, string $table, int $limit): void
{
    assert_real_table($pdo, $table);
    $columns = table_columns($pdo, $table);
    $pkColumns = primary_key_columns($pdo, $table);
    $mode = (string)($_GET['mode'] ?? 'list');
    $search = trim((string)($_GET['search'] ?? ''));

    $actions = '<div class="toolbar"><a class="button" href="' . e(build_url(['page' => 'table', 'table' => $table, 'mode' => 'add'])) . '">Add ' . e(humanize($table)) . '</a></div>';
    page_heading(humanize($table), '', $actions);

    if ($mode === 'add' || $mode === 'edit') {
        $editing = $mode === 'edit';
        $row = [];
        $pk = [];
        if ($editing) {
            $pkSource = is_array($_GET['pk'] ?? null) ? $_GET['pk'] : [];
            $pk = pk_from_array($pkSource, $pkColumns);
            $row = fetch_row_by_pk($pdo, $table, $pk) ?? [];
            if ($row === []) {
                echo '<div class="notice error">The requested row was not found.</div>';
                return;
            }
        }
        ?>
<section class="card">
    <div class="card-header">
        <h2><?= $editing ? 'Edit row' : 'Create row' ?></h2>
        <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => $table])) ?>">Back to list</a>
    </div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="crud_save">
        <input type="hidden" name="return_page" value="table">
        <input type="hidden" name="table" value="<?= e($table) ?>">
        <input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>">
        <?php if ($editing): ?>
            <?php foreach ($pk as $name => $value): ?>
                <input type="hidden" name="pk[<?= e($name) ?>]" value="<?= e($value) ?>">
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="form-grid">
            <?php foreach ($columns as $columnName => $column): ?>
                <?php if (!$editing && is_auto_increment($column)) continue; ?>
                <?php render_generic_field($pdo, $table, $columnName, $column, $row[$columnName] ?? ($column['Default'] ?? ''), $editing, $pkColumns); ?>
            <?php endforeach; ?>
        </div>
        <div class="toolbar" style="margin-top:18px">
            <button type="submit"><?= $editing ? 'Save changes' : 'Create row' ?></button>
            <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => $table])) ?>">Cancel</a>
        </div>
    </form>
</section>
        <?php
        return;
    }

    $rows = select_rows($pdo, $table, $limit, $search);
    ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2>Rows</h2>
            <div class="muted small"><?= number_format(table_row_count($pdo, $table)) ?> total row(s); showing at most <?= number_format($limit) ?>.</div>
        </div>
        <form method="get" class="toolbar">
            <input type="hidden" name="page" value="table">
            <input type="hidden" name="table" value="<?= e($table) ?>">
            <input style="min-width:250px" type="search" name="search" value="<?= e($search) ?>" placeholder="Search records, e.g. Montreal">
            <button type="submit" class="secondary">Search</button>
            <?php if ($search !== ''): ?><a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => $table])) ?>">Clear</a><?php endif; ?>
        </form>
    </div>
    <?php if ($rows === []): ?>
        <div class="empty">No matching rows.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <?php foreach ($columns as $columnName => $_): ?><th><?= e(humanize($columnName)) ?></th><?php endforeach; ?>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $columnName => $_): ?><td><?= format_cell($row[$columnName] ?? null) ?></td><?php endforeach; ?>
                    <td class="actions">
                        <?php if ($pkColumns !== []):
                            $rowPk = [];
                            foreach ($pkColumns as $pkColumn) { $rowPk[$pkColumn] = $row[$pkColumn]; }
                        ?>
                            <a class="button secondary small-button" href="<?= e(build_url(['page' => 'table', 'table' => $table, 'mode' => 'edit', 'pk' => $rowPk])) ?>">Edit</a>
                            <form class="inline" method="post" data-confirm="Delete this row? Foreign-key rules may reject the deletion.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="crud_delete">
                                <input type="hidden" name="return_page" value="table">
                                <input type="hidden" name="table" value="<?= e($table) ?>">
                                <?php foreach ($rowPk as $name => $value): ?>
                                    <input type="hidden" name="pk[<?= e($name) ?>]" value="<?= e($value) ?>">
                                <?php endforeach; ?>
                                <button class="danger small-button" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">No primary key</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</section>
<?php
}

function render_location_history_manager(
    PDO $pdo,
    string $page,
    string $table,
    string $ownerColumn,
    string $ownerInputName,
    int $ownerId,
    string $ownerLabel,
    string $saveAction,
    string $deleteAction
): void {
    $editLocation = isset($_GET['edit_location']) ? (int)$_GET['edit_location'] : 0;
    $editStart = trim((string)($_GET['edit_start'] ?? ''));
    $editing = $editLocation > 0 && $editStart !== '';
    $editRow = [];

    if ($editing) {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . qi($table)
            . ' WHERE ' . qi($ownerColumn) . ' = :owner AND LocationID = :location AND StartDate = :start_date'
        );
        $stmt->execute([
            'owner' => $ownerId,
            'location' => $editLocation,
            'start_date' => $editStart,
        ]);
        $editRow = $stmt->fetch() ?: [];
        if ($editRow === []) {
            throw new RuntimeException('The selected location-history record was not found.');
        }
    }

    $locationLabel = workflow_location_label_expression($pdo, 'l');
    $stmt = $pdo->prepare(
        'SELECT h.LocationID, h.StartDate, h.EndDate, ' . $locationLabel . ' AS LocationName'
        . ' FROM ' . qi($table) . ' h'
        . ' JOIN ' . qi('Location') . ' l ON l.LocationID = h.LocationID'
        . ' WHERE h.' . qi($ownerColumn) . ' = :owner'
        . ' ORDER BY h.StartDate DESC, h.LocationID'
    );
    $stmt->execute(['owner' => $ownerId]);
    $history = $stmt->fetchAll();
    $locations = workflow_location_options($pdo);

    page_heading(
        $ownerLabel . ' — Location History',
        'Add, edit, or end location periods without overwriting previous records.',
        '<a class="button secondary" href="' . e(build_url(['page' => $page])) . '">Back to list</a>'
    );
    ?>
<div class="split">
<section class="card sticky-card">
    <h2><?= $editing ? 'Edit location period' : 'Add location period' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= e($saveAction) ?>">
        <input type="hidden" name="return_page" value="<?= e($page) ?>">
        <input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>">
        <input type="hidden" name="<?= e($ownerInputName) ?>" value="<?= e($ownerId) ?>">
        <?php if ($editing): ?>
            <input type="hidden" name="old_location_id" value="<?= e($editRow['LocationID']) ?>">
            <input type="hidden" name="old_start_date" value="<?= e($editRow['StartDate']) ?>">
        <?php endif; ?>
        <div class="field">
            <label>Location *</label>
            <select name="location_id" required>
                <option value="">— Select —</option>
                <?php foreach ($locations as $option): ?>
                    <option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['LocationID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-grid" style="margin-top:13px">
            <div class="field"><label>Start date *</label><input type="date" name="start_date" value="<?= e($editRow['StartDate'] ?? date('Y-m-d')) ?>" required></div>
            <div class="field"><label>End date</label><input type="date" name="end_date" value="<?= e($editRow['EndDate'] ?? '') ?>"></div>
        </div>
        <div class="toolbar" style="margin-top:17px">
            <button type="submit"><?= $editing ? 'Save location period' : 'Add location period' ?></button>
            <?php if ($editing): ?><a class="button secondary" href="<?= e(build_url(['page' => $page, 'mode' => 'locations', 'id' => $ownerId])) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>
<section class="card">
    <h2>Location history</h2>
    <?php if ($history === []): ?>
        <div class="empty">No location periods are recorded.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Location</th><th>Start date</th><th>End date</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($history as $row): ?>
            <tr>
                <td><?= e($row['LocationName']) ?> <span class="muted">#<?= e($row['LocationID']) ?></span></td>
                <td><?= e($row['StartDate']) ?></td>
                <td><?= format_cell($row['EndDate']) ?></td>
                <td><span class="badge <?= $row['EndDate'] === null ? 'success' : '' ?>"><?= $row['EndDate'] === null ? 'Current' : 'Ended' ?></span></td>
                <td class="actions">
                    <a class="button secondary small-button" href="<?= e(build_url(['page' => $page, 'mode' => 'locations', 'id' => $ownerId, 'edit_location' => $row['LocationID'], 'edit_start' => $row['StartDate']])) ?>">Edit</a>
                    <form class="inline" method="post" data-confirm="Delete this location-history record?">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= e($deleteAction) ?>">
                        <input type="hidden" name="return_page" value="<?= e($page) ?>">
                        <input type="hidden" name="<?= e($ownerInputName) ?>" value="<?= e($ownerId) ?>">
                        <input type="hidden" name="location_id" value="<?= e($row['LocationID']) ?>">
                        <input type="hidden" name="start_date" value="<?= e($row['StartDate']) ?>">
                        <button class="danger small-button" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
</div>
<?php
}

function render_guardianship_manager(PDO $pdo, string $perspective, int $ownerId, string $ownerLabel): void
{
    $familyPerspective = $perspective === 'family';
    $page = $familyPerspective ? 'family_members' : 'club_members';
    $mode = $familyPerspective ? 'relationships' : 'guardians';
    $editFamily = isset($_GET['edit_family']) ? (int)$_GET['edit_family'] : 0;
    $editMember = isset($_GET['edit_member']) ? (int)$_GET['edit_member'] : 0;
    $editStart = trim((string)($_GET['edit_start'] ?? ''));
    $editing = $editFamily > 0 && $editMember > 0 && $editStart !== '';
    $editRow = [];

    if ($editing) {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . qi('Guardianship')
            . ' WHERE MembershipNumber = :member AND FamilyMemberID = :family_member AND StartDate = :start_date'
        );
        $stmt->execute([
            'member' => $editMember,
            'family_member' => $editFamily,
            'start_date' => $editStart,
        ]);
        $editRow = $stmt->fetch() ?: [];
        if ($editRow === []) {
            throw new RuntimeException('The selected family relationship was not found.');
        }
    }

    if ($familyPerspective) {
        $stmt = $pdo->prepare(
            'SELECT g.*, CONCAT(cm.FirstName, \' \', cm.LastName) AS MemberName,'
            . ' CONCAT(fm.FirstName, \' \', fm.LastName) AS FamilyName'
            . ' FROM ' . qi('Guardianship') . ' g'
            . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = g.MembershipNumber'
            . ' JOIN ' . qi('FamilyMember') . ' fm ON fm.FamilyMemberID = g.FamilyMemberID'
            . ' WHERE g.FamilyMemberID = :owner'
            . ' ORDER BY g.StartDate DESC, cm.LastName, cm.FirstName'
        );
        $counterpartOptions = member_options($pdo);
        $counterpartLabel = 'Club member';
    } else {
        $stmt = $pdo->prepare(
            'SELECT g.*, CONCAT(cm.FirstName, \' \', cm.LastName) AS MemberName,'
            . ' CONCAT(fm.FirstName, \' \', fm.LastName) AS FamilyName'
            . ' FROM ' . qi('Guardianship') . ' g'
            . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = g.MembershipNumber'
            . ' JOIN ' . qi('FamilyMember') . ' fm ON fm.FamilyMemberID = g.FamilyMemberID'
            . ' WHERE g.MembershipNumber = :owner'
            . ' ORDER BY g.StartDate DESC, fm.LastName, fm.FirstName'
        );
        $counterpartOptions = workflow_family_member_options($pdo);
        $counterpartLabel = 'Family member';
    }
    $stmt->execute(['owner' => $ownerId]);
    $relationships = $stmt->fetchAll();
    $relationshipTypes = workflow_relationship_values($pdo);

    page_heading(
        $ownerLabel . ' — Family Relationships',
        'Manage the related club member, relationship type, and Primary/Secondary status.',
        '<a class="button secondary" href="' . e(build_url(['page' => $page])) . '">Back to list</a>'
    );
    ?>
<div class="split">
<section class="card sticky-card">
    <h2><?= $editing ? 'Edit relationship' : 'Add relationship' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="guardianship_save">
        <input type="hidden" name="return_page" value="<?= e($page) ?>">
        <input type="hidden" name="relationship_return_page" value="<?= e($page) ?>">
        <input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>">
        <?php if ($familyPerspective): ?>
            <input type="hidden" name="family_member_id" value="<?= e($ownerId) ?>">
            <div class="field"><label><?= e($counterpartLabel) ?> *</label><select name="membership_number" required><option value="">— Select —</option><?php foreach ($counterpartOptions as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['MembershipNumber'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
        <?php else: ?>
            <input type="hidden" name="membership_number" value="<?= e($ownerId) ?>">
            <div class="field"><label><?= e($counterpartLabel) ?> *</label><select name="family_member_id" required><option value="">— Select —</option><?php foreach ($counterpartOptions as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['FamilyMemberID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <?php if ($editing): ?>
            <input type="hidden" name="old_membership_number" value="<?= e($editRow['MembershipNumber']) ?>">
            <input type="hidden" name="old_family_member_id" value="<?= e($editRow['FamilyMemberID']) ?>">
            <input type="hidden" name="old_start_date" value="<?= e($editRow['StartDate']) ?>">
        <?php endif; ?>
        <div class="form-grid" style="margin-top:13px">
            <div class="field"><label>Relationship *</label><select name="relationship_type" required><option value="">— Select —</option><?php foreach ($relationshipTypes as $type): ?><option value="<?= e($type) ?>"<?= (string)($editRow['RelationshipType'] ?? '') === $type ? ' selected' : '' ?>><?= e($type) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Designation *</label><select name="is_primary" required><option value="1"<?= (string)($editRow['IsPrimary'] ?? '1') === '1' ? ' selected' : '' ?>>Primary</option><option value="0"<?= (string)($editRow['IsPrimary'] ?? '') === '0' ? ' selected' : '' ?>>Secondary</option></select></div>
            <div class="field"><label>Start date *</label><input type="date" name="start_date" value="<?= e($editRow['StartDate'] ?? date('Y-m-d')) ?>" required></div>
            <div class="field"><label>End date</label><input type="date" name="end_date" value="<?= e($editRow['EndDate'] ?? '') ?>"></div>
        </div>
        <div class="toolbar" style="margin-top:17px">
            <button type="submit"><?= $editing ? 'Save relationship' : 'Add relationship' ?></button>
            <?php if ($editing): ?><a class="button secondary" href="<?= e(build_url(['page' => $page, 'mode' => $mode, 'id' => $ownerId])) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>
<section class="card">
    <h2>Recorded relationships</h2>
    <?php if ($relationships === []): ?>
        <div class="empty">No family relationships are recorded.</div>
    <?php else: ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Club member</th><th>Family member</th><th>Relationship</th><th>Designation</th><th>Start</th><th>End</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($relationships as $row): ?>
            <tr>
                <td>#<?= e($row['MembershipNumber']) ?> · <?= e($row['MemberName']) ?></td>
                <td>#<?= e($row['FamilyMemberID']) ?> · <?= e($row['FamilyName']) ?></td>
                <td><?= e($row['RelationshipType']) ?></td>
                <td><span class="badge <?= (int)$row['IsPrimary'] === 1 ? 'success' : '' ?>"><?= (int)$row['IsPrimary'] === 1 ? 'Primary' : 'Secondary' ?></span></td>
                <td><?= e($row['StartDate']) ?></td>
                <td><?= format_cell($row['EndDate']) ?></td>
                <td class="actions">
                    <a class="button secondary small-button" href="<?= e(build_url(['page' => $page, 'mode' => $mode, 'id' => $ownerId, 'edit_family' => $row['FamilyMemberID'], 'edit_member' => $row['MembershipNumber'], 'edit_start' => $row['StartDate']])) ?>">Edit</a>
                    <form class="inline" method="post" data-confirm="Delete this family relationship?">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="guardianship_delete">
                        <input type="hidden" name="return_page" value="<?= e($page) ?>">
                        <input type="hidden" name="relationship_return_page" value="<?= e($page) ?>">
                        <input type="hidden" name="family_member_id" value="<?= e($row['FamilyMemberID']) ?>">
                        <input type="hidden" name="membership_number" value="<?= e($row['MembershipNumber']) ?>">
                        <input type="hidden" name="start_date" value="<?= e($row['StartDate']) ?>">
                        <button class="danger small-button" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
</div>
<?php
}

function render_personnel_page(PDO $pdo): void
{
    foreach (['Personnel', 'WorksAt', 'Location', 'Position'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $mode = (string)($_GET['mode'] ?? 'list');
    $personnelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($mode === 'assignments') {
        if ($personnelId <= 0) {
            throw new InvalidArgumentException('Select a personnel record.');
        }
        $person = fetch_row_by_pk($pdo, 'Personnel', ['PersonnelID' => $personnelId]);
        if ($person === null) {
            throw new RuntimeException('The selected personnel record was not found.');
        }

        $editLocation = isset($_GET['edit_location']) ? (int)$_GET['edit_location'] : 0;
        $editStart = trim((string)($_GET['edit_start'] ?? ''));
        $editing = $editLocation > 0 && $editStart !== '';
        $editRow = [];
        if ($editing) {
            $stmt = $pdo->prepare(
                'SELECT * FROM ' . qi('WorksAt')
                . ' WHERE PersonnelID = :personnel AND LocationID = :location AND StartDate = :start_date'
            );
            $stmt->execute(['personnel' => $personnelId, 'location' => $editLocation, 'start_date' => $editStart]);
            $editRow = $stmt->fetch() ?: [];
            if ($editRow === []) {
                throw new RuntimeException('The selected assignment was not found.');
            }
        }

        $locationLabel = workflow_location_label_expression($pdo, 'l');
        $stmt = $pdo->prepare(
            'SELECT wa.*, ' . $locationLabel . ' AS LocationName, pos.PositionName'
            . ' FROM ' . qi('WorksAt') . ' wa'
            . ' JOIN ' . qi('Location') . ' l ON l.LocationID = wa.LocationID'
            . ' JOIN ' . qi('Position') . ' pos ON pos.PositionID = wa.PositionID'
            . ' WHERE wa.PersonnelID = :personnel'
            . ' ORDER BY wa.StartDate DESC, wa.LocationID'
        );
        $stmt->execute(['personnel' => $personnelId]);
        $history = $stmt->fetchAll();
        $locations = workflow_location_options($pdo);
        $positions = workflow_position_options($pdo);
        $name = trim((string)$person['FirstName'] . ' ' . (string)$person['LastName']);

        page_heading(
            $name . ' — Work History',
            'Maintain the personnel member’s location, position, start date, and end date.',
            '<a class="button secondary" href="' . e(build_url(['page' => 'personnel'])) . '">Back to personnel</a>'
        );
        ?>
<div class="split">
<section class="card sticky-card">
    <h2><?= $editing ? 'Edit assignment' : 'Add assignment' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="worksat_save">
        <input type="hidden" name="return_page" value="personnel">
        <input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>">
        <input type="hidden" name="personnel_id" value="<?= e($personnelId) ?>">
        <?php if ($editing): ?><input type="hidden" name="old_location_id" value="<?= e($editRow['LocationID']) ?>"><input type="hidden" name="old_start_date" value="<?= e($editRow['StartDate']) ?>"><?php endif; ?>
        <div class="field"><label>Location *</label><select name="location_id" required><option value="">— Select —</option><?php foreach ($locations as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['LocationID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="margin-top:13px"><label>Position *</label><select name="position_id" required><option value="">— Select —</option><?php foreach ($positions as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['PositionID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
        <div class="form-grid" style="margin-top:13px"><div class="field"><label>Start date *</label><input type="date" name="start_date" value="<?= e($editRow['StartDate'] ?? date('Y-m-d')) ?>" required></div><div class="field"><label>End date</label><input type="date" name="end_date" value="<?= e($editRow['EndDate'] ?? '') ?>"></div></div>
        <div class="toolbar" style="margin-top:17px"><button type="submit"><?= $editing ? 'Save assignment' : 'Add assignment' ?></button><?php if ($editing): ?><a class="button secondary" href="<?= e(build_url(['page' => 'personnel', 'mode' => 'assignments', 'id' => $personnelId])) ?>">Cancel</a><?php endif; ?></div>
    </form>
</section>
<section class="card">
    <h2>Recorded assignments</h2>
    <?php if ($history === []): ?><div class="empty">No work assignments are recorded.</div><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Location</th><th>Position</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($history as $row): ?><tr><td><?= e($row['LocationName']) ?> <span class="muted">#<?= e($row['LocationID']) ?></span></td><td><?= e($row['PositionName']) ?></td><td><?= e($row['StartDate']) ?></td><td><?= format_cell($row['EndDate']) ?></td><td><span class="badge <?= $row['EndDate'] === null ? 'success' : '' ?>"><?= $row['EndDate'] === null ? 'Current' : 'Ended' ?></span></td><td class="actions"><a class="button secondary small-button" href="<?= e(build_url(['page' => 'personnel', 'mode' => 'assignments', 'id' => $personnelId, 'edit_location' => $row['LocationID'], 'edit_start' => $row['StartDate']])) ?>">Edit</a><form class="inline" method="post" data-confirm="Delete this personnel assignment?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="worksat_delete"><input type="hidden" name="return_page" value="personnel"><input type="hidden" name="personnel_id" value="<?= e($personnelId) ?>"><input type="hidden" name="location_id" value="<?= e($row['LocationID']) ?>"><input type="hidden" name="start_date" value="<?= e($row['StartDate']) ?>"><button class="danger small-button" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>
</div>
<?php
        return;
    }

    if ($mode === 'add' || $mode === 'edit') {
        $editing = $mode === 'edit';
        $row = [];
        if ($editing) {
            if ($personnelId <= 0) {
                throw new InvalidArgumentException('Select a personnel record.');
            }
            $row = fetch_row_by_pk($pdo, 'Personnel', ['PersonnelID' => $personnelId]) ?? [];
            if ($row === []) {
                throw new RuntimeException('The selected personnel record was not found.');
            }
        }
        $locations = workflow_location_options($pdo);
        $positions = workflow_position_options($pdo);
        page_heading(
            $editing ? 'Edit Personnel' : 'Create Personnel',
            $editing ? 'Update the personnel member’s personal information.' : 'Create the personnel record and its initial work assignment.',
            '<a class="button secondary" href="' . e(build_url(['page' => 'personnel'])) . '">Back to personnel</a>'
        );
        ?>
<section class="card">
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="personnel_save"><input type="hidden" name="return_page" value="personnel"><input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>"><?php if ($editing): ?><input type="hidden" name="personnel_id" value="<?= e($personnelId) ?>"><?php endif; ?>
    <h2>Personnel information</h2><div class="form-grid"><?php workflow_render_base_fields($pdo, 'Personnel', $row, $editing); ?></div>
    <?php if (!$editing): ?>
        <h2 style="margin-top:24px">Initial work assignment</h2>
        <div class="form-grid"><div class="field"><label>Location *</label><select name="location_id" required><option value="">— Select —</option><?php foreach ($locations as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Position *</label><select name="position_id" required><option value="">— Select —</option><?php foreach ($positions as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Start date *</label><input type="date" name="assignment_start_date" value="<?= e(date('Y-m-d')) ?>" required></div><div class="field"><label>End date</label><input type="date" name="assignment_end_date"></div></div>
    <?php endif; ?>
    <div class="toolbar" style="margin-top:18px"><button type="submit"><?= $editing ? 'Save personnel' : 'Create personnel' ?></button><a class="button secondary" href="<?= e(build_url(['page' => 'personnel'])) ?>">Cancel</a></div>
</form>
</section>
<?php
        return;
    }

    $locationLabel = workflow_location_label_expression($pdo, 'l');
    $rows = $pdo->query(
        'SELECT p.PersonnelID, p.FirstName, p.LastName, p.Email, p.Phone, p.Mandate,'
        . ' wa.LocationID, wa.StartDate, ' . $locationLabel . ' AS LocationName, pos.PositionName'
        . ' FROM ' . qi('Personnel') . ' p'
        . ' LEFT JOIN ' . qi('WorksAt') . ' wa ON wa.PersonnelID = p.PersonnelID'
        . ' AND wa.EndDate IS NULL'
        . ' AND wa.StartDate = (SELECT MAX(w2.StartDate) FROM ' . qi('WorksAt') . ' w2 WHERE w2.PersonnelID = p.PersonnelID AND w2.EndDate IS NULL)'
        . ' LEFT JOIN ' . qi('Location') . ' l ON l.LocationID = wa.LocationID'
        . ' LEFT JOIN ' . qi('Position') . ' pos ON pos.PositionID = wa.PositionID'
        . ' ORDER BY p.LastName, p.FirstName, p.PersonnelID LIMIT 250'
    )->fetchAll();

    page_heading(
        'Personnel',
        'Create, edit, delete, and display personnel together with their work-assignment history.',
        '<a class="button" href="' . e(build_url(['page' => 'personnel', 'mode' => 'add'])) . '">Add Personnel</a>'
    );
    ?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>ID</th><th>Name</th><th>Mandate</th><th>Email</th><th>Phone</th><th>Current location</th><th>Current position</th><th>Since</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= e($row['PersonnelID']) ?></td><td><?= e($row['FirstName'] . ' ' . $row['LastName']) ?></td><td><?= e($row['Mandate']) ?></td><td><?= e($row['Email']) ?></td><td><?= e($row['Phone']) ?></td><td><?= format_cell($row['LocationName']) ?></td><td><?= format_cell($row['PositionName']) ?></td><td><?= format_cell($row['StartDate']) ?></td><td class="actions"><a class="button secondary small-button" href="<?= e(build_url(['page' => 'personnel', 'mode' => 'edit', 'id' => $row['PersonnelID']])) ?>">Edit</a><a class="button secondary small-button" href="<?= e(build_url(['page' => 'personnel', 'mode' => 'assignments', 'id' => $row['PersonnelID']])) ?>">Work history</a><form class="inline" method="post" data-confirm="Delete this personnel record and its work history?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="personnel_delete"><input type="hidden" name="return_page" value="personnel"><input type="hidden" name="personnel_id" value="<?= e($row['PersonnelID']) ?>"><button class="danger small-button" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php
}

function render_family_members_page(PDO $pdo): void
{
    foreach (['FamilyMember', 'FamilyMemberLocation', 'Guardianship', 'Location', 'ClubMember'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $mode = (string)($_GET['mode'] ?? 'list');
    $familyMemberId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($mode === 'locations') {
        $row = fetch_row_by_pk($pdo, 'FamilyMember', ['FamilyMemberID' => $familyMemberId]);
        if ($row === null) {
            throw new RuntimeException('The selected family member was not found.');
        }
        render_location_history_manager($pdo, 'family_members', 'FamilyMemberLocation', 'FamilyMemberID', 'family_member_id', $familyMemberId, $row['FirstName'] . ' ' . $row['LastName'], 'family_location_save', 'family_location_delete');
        return;
    }
    if ($mode === 'relationships') {
        $row = fetch_row_by_pk($pdo, 'FamilyMember', ['FamilyMemberID' => $familyMemberId]);
        if ($row === null) {
            throw new RuntimeException('The selected family member was not found.');
        }
        render_guardianship_manager($pdo, 'family', $familyMemberId, $row['FirstName'] . ' ' . $row['LastName']);
        return;
    }
    if ($mode === 'add' || $mode === 'edit') {
        $editing = $mode === 'edit';
        $row = [];
        if ($editing) {
            $row = fetch_row_by_pk($pdo, 'FamilyMember', ['FamilyMemberID' => $familyMemberId]) ?? [];
            if ($row === []) {
                throw new RuntimeException('The selected family member was not found.');
            }
        }
        $locations = workflow_location_options($pdo);
        $members = member_options($pdo);
        $relationships = workflow_relationship_values($pdo);
        page_heading($editing ? 'Edit Family Member' : 'Create Family Member', $editing ? 'Update the family member’s personal information.' : 'Create the family member, initial location, and an optional child relationship.', '<a class="button secondary" href="' . e(build_url(['page' => 'family_members'])) . '">Back to family members</a>');
        ?>
<section class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="family_member_save"><input type="hidden" name="return_page" value="family_members"><input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>"><?php if ($editing): ?><input type="hidden" name="family_member_id" value="<?= e($familyMemberId) ?>"><?php endif; ?><h2>Family-member information</h2><div class="form-grid"><?php workflow_render_base_fields($pdo, 'FamilyMember', $row, $editing); ?></div>
<?php if (!$editing): ?><h2 style="margin-top:24px">Initial location</h2><div class="form-grid"><div class="field"><label>Location *</label><select name="location_id" required><option value="">— Select —</option><?php foreach ($locations as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Start date *</label><input type="date" name="location_start_date" value="<?= e(date('Y-m-d')) ?>" required></div><div class="field"><label>End date</label><input type="date" name="location_end_date"></div></div><h2 style="margin-top:24px">Initial child relationship <span class="muted small">(optional)</span></h2><div class="form-grid"><div class="field"><label>Club member</label><select name="membership_number"><option value="">— None —</option><?php foreach ($members as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Relationship</label><select name="relationship_type"><option value="">— Select —</option><?php foreach ($relationships as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select></div><div class="field"><label>Designation</label><select name="is_primary"><option value="1">Primary</option><option value="0">Secondary</option></select></div><div class="field"><label>Relationship start date</label><input type="date" name="relationship_start_date" value="<?= e(date('Y-m-d')) ?>"></div><div class="field"><label>Relationship end date</label><input type="date" name="relationship_end_date"></div></div><?php endif; ?><div class="toolbar" style="margin-top:18px"><button type="submit"><?= $editing ? 'Save family member' : 'Create family member' ?></button><a class="button secondary" href="<?= e(build_url(['page' => 'family_members'])) ?>">Cancel</a></div></form></section>
<?php
        return;
    }

    $locationLabel = workflow_location_label_expression($pdo, 'l');
    $rows = $pdo->query(
        'SELECT fm.FamilyMemberID, fm.FirstName, fm.LastName, fm.Email, fm.Phone,'
        . ' fml.LocationID, ' . $locationLabel . ' AS LocationName,'
        . ' (SELECT COUNT(DISTINCT g.MembershipNumber) FROM ' . qi('Guardianship') . ' g'
        . ' WHERE g.FamilyMemberID = fm.FamilyMemberID'
        . ' AND g.StartDate <= CURDATE() AND (g.EndDate IS NULL OR g.EndDate >= CURDATE())) AS ActiveChildren'
        . ' FROM ' . qi('FamilyMember') . ' fm'
        . ' LEFT JOIN ' . qi('FamilyMemberLocation') . ' fml ON fml.FamilyMemberID = fm.FamilyMemberID'
        . ' AND fml.EndDate IS NULL'
        . ' AND fml.StartDate = (SELECT MAX(f2.StartDate) FROM ' . qi('FamilyMemberLocation') . ' f2 WHERE f2.FamilyMemberID = fm.FamilyMemberID AND f2.EndDate IS NULL)'
        . ' LEFT JOIN ' . qi('Location') . ' l ON l.LocationID = fml.LocationID'
        . ' ORDER BY fm.LastName, fm.FirstName, fm.FamilyMemberID LIMIT 250'
    )->fetchAll();
    page_heading('Family Members', 'Create, edit, delete, and display family members with location history and Primary/Secondary child relationships.', '<a class="button" href="' . e(build_url(['page' => 'family_members', 'mode' => 'add'])) . '">Add Family Member</a>');
    ?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Current location</th><th>Active children</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><td><?= e($row['FamilyMemberID']) ?></td><td><?= e($row['FirstName'] . ' ' . $row['LastName']) ?></td><td><?= e($row['Email']) ?></td><td><?= e($row['Phone']) ?></td><td><?= format_cell($row['LocationName']) ?></td><td><?= e($row['ActiveChildren']) ?></td><td class="actions"><a class="button secondary small-button" href="<?= e(build_url(['page' => 'family_members', 'mode' => 'edit', 'id' => $row['FamilyMemberID']])) ?>">Edit</a><a class="button secondary small-button" href="<?= e(build_url(['page' => 'family_members', 'mode' => 'locations', 'id' => $row['FamilyMemberID']])) ?>">Locations</a><a class="button secondary small-button" href="<?= e(build_url(['page' => 'family_members', 'mode' => 'relationships', 'id' => $row['FamilyMemberID']])) ?>">Relationships</a><form class="inline" method="post" data-confirm="Delete this family member, location history, and relationships?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="family_member_delete"><input type="hidden" name="return_page" value="family_members"><input type="hidden" name="family_member_id" value="<?= e($row['FamilyMemberID']) ?>"><button class="danger small-button" type="submit">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php
}

function render_club_members_page(PDO $pdo): void
{
    foreach (['ClubMember', 'ClubMemberLocation', 'Guardianship', 'Location', 'FamilyMember'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $mode = (string)($_GET['mode'] ?? 'list');
    $membershipNumber = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($mode === 'locations') {
        $row = fetch_row_by_pk($pdo, 'ClubMember', ['MembershipNumber' => $membershipNumber]);
        if ($row === null) {
            throw new RuntimeException('The selected club member was not found.');
        }
        render_location_history_manager($pdo, 'club_members', 'ClubMemberLocation', 'MembershipNumber', 'membership_number', $membershipNumber, $row['FirstName'] . ' ' . $row['LastName'], 'club_location_save', 'club_location_delete');
        return;
    }
    if ($mode === 'guardians') {
        $row = fetch_row_by_pk($pdo, 'ClubMember', ['MembershipNumber' => $membershipNumber]);
        if ($row === null) {
            throw new RuntimeException('The selected club member was not found.');
        }
        render_guardianship_manager($pdo, 'club', $membershipNumber, $row['FirstName'] . ' ' . $row['LastName']);
        return;
    }
    if ($mode === 'add' || $mode === 'edit') {
        $editing = $mode === 'edit';
        $row = [];
        if ($editing) {
            $row = fetch_row_by_pk($pdo, 'ClubMember', ['MembershipNumber' => $membershipNumber]) ?? [];
            if ($row === []) {
                throw new RuntimeException('The selected club member was not found.');
            }
        }
        $locations = workflow_location_options($pdo);
        $familyMembers = workflow_family_member_options($pdo);
        $relationships = workflow_relationship_values($pdo);
        page_heading($editing ? 'Edit Club Member' : 'Create Club Member', $editing ? 'Update the club member’s personal information.' : 'Create the member, initial location, and required family relationship for a minor.', '<a class="button secondary" href="' . e(build_url(['page' => 'club_members'])) . '">Back to club members</a>');
        ?>
<section class="card"><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="club_member_save"><input type="hidden" name="return_page" value="club_members"><input type="hidden" name="mode" value="<?= $editing ? 'update' : 'insert' ?>"><?php if ($editing): ?><input type="hidden" name="membership_number" value="<?= e($membershipNumber) ?>"><?php endif; ?><h2>Club-member information</h2><div class="form-grid"><?php workflow_render_base_fields($pdo, 'ClubMember', $row, $editing); ?></div>
<?php if (!$editing): ?><h2 style="margin-top:24px">Initial location</h2><div class="form-grid"><div class="field"><label>Location *</label><select name="location_id" required><option value="">— Select —</option><?php foreach ($locations as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Start date *</label><input type="date" name="location_start_date" value="<?= e(date('Y-m-d')) ?>" required></div><div class="field"><label>End date</label><input type="date" name="location_end_date"></div></div><h2 style="margin-top:24px">Family relationship</h2><p class="muted small">A family member is required when the date of birth makes the new member a minor.</p><div class="form-grid"><div class="field"><label>Family member</label><select name="family_member_id"><option value="">— None for a major member —</option><?php foreach ($familyMembers as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Relationship</label><select name="relationship_type"><option value="">— Select —</option><?php foreach ($relationships as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?></select></div><div class="field"><label>Designation</label><select name="is_primary"><option value="1">Primary</option><option value="0">Secondary</option></select></div><div class="field"><label>Relationship start date</label><input type="date" name="relationship_start_date" value="<?= e(date('Y-m-d')) ?>"></div><div class="field"><label>Relationship end date</label><input type="date" name="relationship_end_date"></div></div><?php endif; ?><div class="toolbar" style="margin-top:18px"><button type="submit"><?= $editing ? 'Save club member' : 'Create club member' ?></button><a class="button secondary" href="<?= e(build_url(['page' => 'club_members'])) ?>">Cancel</a></div></form></section>
<?php
        return;
    }

    $locationLabel = workflow_location_label_expression($pdo, 'l');
    $rows = $pdo->query(
        'SELECT cm.MembershipNumber, cm.FirstName, cm.LastName, cm.DOB, cm.Gender, cm.Email, cm.Phone,'
        . ' cml.LocationID, ' . $locationLabel . ' AS LocationName,'
        . ' (SELECT GROUP_CONCAT(DISTINCT CONCAT(fm2.FirstName, \' \', fm2.LastName) ORDER BY fm2.LastName SEPARATOR \', \')'
        . ' FROM ' . qi('Guardianship') . ' g2'
        . ' JOIN ' . qi('FamilyMember') . ' fm2 ON fm2.FamilyMemberID = g2.FamilyMemberID'
        . ' WHERE g2.MembershipNumber = cm.MembershipNumber AND g2.IsPrimary = 1'
        . ' AND g2.StartDate <= CURDATE() AND (g2.EndDate IS NULL OR g2.EndDate >= CURDATE())) AS PrimaryGuardian'
        . ' FROM ' . qi('ClubMember') . ' cm'
        . ' LEFT JOIN ' . qi('ClubMemberLocation') . ' cml ON cml.MembershipNumber = cm.MembershipNumber'
        . ' AND cml.EndDate IS NULL'
        . ' AND cml.StartDate = (SELECT MAX(c2.StartDate) FROM ' . qi('ClubMemberLocation') . ' c2 WHERE c2.MembershipNumber = cm.MembershipNumber AND c2.EndDate IS NULL)'
        . ' LEFT JOIN ' . qi('Location') . ' l ON l.LocationID = cml.LocationID'
        . ' ORDER BY cm.LastName, cm.FirstName, cm.MembershipNumber LIMIT 250'
    )->fetchAll();
    page_heading('Club Members', 'Create, edit, delete, and display major/minor members with location history and family relationships.', '<a class="button" href="' . e(build_url(['page' => 'club_members', 'mode' => 'add'])) . '">Add Club Member</a>');
    ?>
<section class="card"><div class="table-wrap"><table><thead><tr><th>Membership</th><th>Name</th><th>Type</th><th>Date of birth</th><th>Gender</th><th>Email</th><th>Current location</th><th>Primary guardian</th><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): $age = age_on_date((string)$row['DOB'], date('Y-m-d')); ?><tr><td><?= e($row['MembershipNumber']) ?></td><td><?= e($row['FirstName'] . ' ' . $row['LastName']) ?></td><td><span class="badge <?= $age < 18 ? 'warning' : 'success' ?>"><?= $age < 18 ? 'Minor' : 'Major' ?></span></td><td><?= e($row['DOB']) ?></td><td><?= e($row['Gender']) ?></td><td><?= e($row['Email']) ?></td><td><?= format_cell($row['LocationName']) ?></td><td><?= format_cell($row['PrimaryGuardian']) ?></td><td class="actions"><a class="button secondary small-button" href="<?= e(build_url(['page' => 'club_members', 'mode' => 'edit', 'id' => $row['MembershipNumber']])) ?>">Edit</a><a class="button secondary small-button" href="<?= e(build_url(['page' => 'club_members', 'mode' => 'locations', 'id' => $row['MembershipNumber']])) ?>">Locations</a><a class="button secondary small-button" href="<?= e(build_url(['page' => 'club_members', 'mode' => 'guardians', 'id' => $row['MembershipNumber']])) ?>">Family</a><form class="inline" method="post" data-confirm="Delete this club member, location history, and family relationships?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="club_member_delete"><input type="hidden" name="return_page" value="club_members"><input type="hidden" name="membership_number" value="<?= e($row['MembershipNumber']) ?>"><button class="danger small-button" type="submit">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php
}

function team_options(PDO $pdo): array
{
    if (!table_exists($pdo, 'Team')) {
        return [];
    }
    return fetch_simple_options(
        $pdo,
        'Team',
        'TeamID',
        "CONCAT(TeamName, ' — ', Gender, ' — location #', LocationID)",
        'TeamName'
    );
}

function personnel_options(PDO $pdo): array
{
    if (!table_exists($pdo, 'Personnel')) {
        return [];
    }
    return fetch_simple_options(
        $pdo,
        'Personnel',
        'PersonnelID',
        "CONCAT(PersonnelID, ' — ', FirstName, ' ', LastName, ' — ', Email)",
        'LastName, FirstName'
    );
}

function member_options(PDO $pdo): array
{
    if (!table_exists($pdo, 'ClubMember')) {
        return [];
    }
    return fetch_simple_options(
        $pdo,
        'ClubMember',
        'MembershipNumber',
        "CONCAT(MembershipNumber, ' — ', FirstName, ' ', LastName, ' — ', Gender)",
        'LastName, FirstName'
    );
}

function formation_options(PDO $pdo): array
{
    if (!table_exists($pdo, 'TeamFormation') || !table_exists($pdo, 'Session') || !table_exists($pdo, 'Team')) {
        return [];
    }
    return $pdo->query(
        'SELECT tf.FormationID AS option_value,'
        . " CONCAT(tf.FormationID, ' — ', t.TeamName, ' — ', DATE_FORMAT(s.SessionDateTime, '%Y-%m-%d %H:%i'), ' — ', s.SessionType) AS option_label"
        . ' FROM ' . qi('TeamFormation') . ' tf'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' ORDER BY s.SessionDateTime DESC, t.TeamName'
    )->fetchAll();
}

function render_formations_page(PDO $pdo): void
{
    page_heading(
        'Sessions and Team Formations',
        'Create, edit, delete, and display a complete game/training session with its two team formations.'
    );

    $required = ['Session', 'TeamFormation', 'Team', 'Personnel', 'FormationAssignment'];
    foreach ($required as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $teams = team_options($pdo);
    $coaches = personnel_options($pdo);
    $editSessionId = isset($_GET['edit_session']) ? (int)$_GET['edit_session'] : 0;
    $editing = $editSessionId > 0;
    $sessionValue = [];
    $formationValues = [[], []];
    if ($editing) {
        $bundle = workflow_session_bundle($pdo, $editSessionId);
        $sessionValue = $bundle['session'];
        foreach (array_values($bundle['formations']) as $index => $formationValue) {
            $formationValues[$index] = $formationValue;
        }
    }

    $formations = $pdo->query(
        'SELECT tf.FormationID, s.SessionID, s.SessionDateTime, s.SessionType, s.Address,'
        . ' t.TeamName, t.Gender, tf.Score,'
        . " CONCAT(p.FirstName, ' ', p.LastName) AS CoachName,"
        . ' COUNT(fa.MembershipNumber) AS PlayerCount'
        . ' FROM ' . qi('TeamFormation') . ' tf'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Personnel') . ' p ON p.PersonnelID = tf.HeadCoachID'
        . ' LEFT JOIN ' . qi('FormationAssignment') . ' fa ON fa.FormationID = tf.FormationID'
        . ' GROUP BY tf.FormationID, s.SessionID, s.SessionDateTime, s.SessionType, s.Address, t.TeamName, t.Gender, tf.Score, p.FirstName, p.LastName'
        . ' ORDER BY s.SessionDateTime DESC, tf.FormationID DESC LIMIT 150'
    )->fetchAll();
    ?>
<div class="split">
<section class="card sticky-card">
    <div class="card-header">
        <div><h2><?= $editing ? 'Edit session #' . e($editSessionId) : 'Create a complete session' ?></h2><div class="muted small">The workflow saves one <code>Session</code> and exactly two <code>TeamFormation</code> records.</div></div>
        <?php if ($editing): ?><a class="button secondary" href="<?= e(build_url(['page' => 'formations'])) ?>">Cancel</a><?php endif; ?>
    </div>
    <?php if ($editing && count($bundle['formations']) === 1): ?>
        <div class="notice warning">This session currently has one team formation. Saving it will add the required second formation.</div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'update_session_formations' : 'create_session_formations' ?>">
        <input type="hidden" name="return_page" value="formations">
        <?php if ($editing): ?><input type="hidden" name="session_id" value="<?= e($editSessionId) ?>"><?php endif; ?>
        <div class="form-grid">
            <div class="field full">
                <label for="session_datetime">Session date and time *</label>
                <input id="session_datetime" type="datetime-local" name="session_datetime" value="<?= e(isset($sessionValue['SessionDateTime']) ? str_replace(' ', 'T', substr((string)$sessionValue['SessionDateTime'], 0, 16)) : '') ?>" required>
            </div>
            <div class="field full">
                <label for="address">Address *</label>
                <input id="address" name="address" maxlength="150" value="<?= e($sessionValue['Address'] ?? '') ?>" required>
            </div>
            <div class="field full">
                <label for="session_type">Nature *</label>
                <select id="session_type" name="session_type" required>
                    <option value="Training"<?= (string)($sessionValue['SessionType'] ?? 'Training') === 'Training' ? ' selected' : '' ?>>Training</option>
                    <option value="Game"<?= (string)($sessionValue['SessionType'] ?? '') === 'Game' ? ' selected' : '' ?>>Game</option>
                </select>
            </div>
        </div>
        <h3 style="margin-top:20px">Team 1</h3>
        <div class="form-grid">
            <div class="field full"><label>Team *</label><select name="team_1" required><option value="">— Select —</option><?php foreach ($teams as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($formationValues[0]['TeamID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Head coach *</label><select name="coach_1" required><option value="">— Select —</option><?php foreach ($coaches as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($formationValues[0]['HeadCoachID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Score</label><input type="number" min="0" step="1" name="score_1" value="<?= e($formationValues[0]['Score'] ?? '') ?>"><span class="hint">Ignored for training sessions.</span></div>
        </div>
        <h3 style="margin-top:20px">Team 2</h3>
        <div class="form-grid">
            <div class="field full"><label>Team *</label><select name="team_2" required><option value="">— Select —</option><?php foreach ($teams as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($formationValues[1]['TeamID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Head coach *</label><select name="coach_2" required><option value="">— Select —</option><?php foreach ($coaches as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($formationValues[1]['HeadCoachID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Score</label><input type="number" min="0" step="1" name="score_2" value="<?= e($formationValues[1]['Score'] ?? '') ?>"><span class="hint">Ignored for training sessions.</span></div>
        </div>
        <button style="margin-top:18px" type="submit"><?= $editing ? 'Save session and formations' : 'Create session and formations' ?></button>
    </form>
</section>

<section class="card">
    <div class="card-header">
        <div><h2>Existing formations</h2><div class="muted small">Latest 150 rows, with player counts. Edit or delete acts on the complete two-team session.</div></div>
        <div class="toolbar">
            <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'Session'])) ?>">Manage sessions</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'TeamFormation'])) ?>">Manage formations</a>
            <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'Team'])) ?>">Manage teams</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Formation</th><th>Session</th><th>Date/time</th><th>Type</th><th>Team</th><th>Gender</th><th>Coach</th><th>Score</th><th>Players</th><th>Address</th><th>Session actions</th></tr></thead>
            <tbody>
            <?php $shownSessionActions = []; ?>
            <?php foreach ($formations as $row): ?>
                <?php $showActions = !isset($shownSessionActions[(int)$row['SessionID']]); $shownSessionActions[(int)$row['SessionID']] = true; ?>
                <tr>
                    <td><?= e($row['FormationID']) ?></td>
                    <td><?= e($row['SessionID']) ?></td>
                    <td><?= e($row['SessionDateTime']) ?></td>
                    <td><?= e($row['SessionType']) ?></td>
                    <td><?= e($row['TeamName']) ?></td>
                    <td><?= e($row['Gender']) ?></td>
                    <td><?= e($row['CoachName']) ?></td>
                    <td><?= format_cell($row['Score']) ?></td>
                    <td><?= e($row['PlayerCount']) ?></td>
                    <td><?= e($row['Address']) ?></td>
                    <td class="actions">
                        <?php if ($showActions): ?>
                            <a class="button secondary small-button" href="<?= e(build_url(['page' => 'formations', 'edit_session' => $row['SessionID']])) ?>">Edit</a>
                            <form class="inline" method="post" data-confirm="Delete this session, both formations, and all of their player assignments?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_session_formations">
                                <input type="hidden" name="return_page" value="formations">
                                <input type="hidden" name="session_id" value="<?= e($row['SessionID']) ?>">
                                <button class="danger small-button" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">Same session</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
<?php
}

function render_assignments_page(PDO $pdo, bool $enforcePayment): void
{
    page_heading(
        'Formation Assignments',
        'Assign, edit, and delete club-member formation assignments. The GUI validates location, team gender, and the three-hour same-day rule.'
    );

    foreach (['FormationAssignment', 'TeamFormation', 'Team', 'Session', 'ClubMember', 'ClubMemberLocation'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $formations = formation_options($pdo);
    $members = member_options($pdo);
    $roles = enum_values((string)(table_columns($pdo, 'FormationAssignment')['Role']['Type'] ?? ''));
    $editFormationId = isset($_GET['edit_formation']) ? (int)$_GET['edit_formation'] : 0;
    $editMembershipNumber = isset($_GET['edit_member']) ? (int)$_GET['edit_member'] : 0;
    $editing = $editFormationId > 0 && $editMembershipNumber > 0;
    $editRow = [];
    if ($editing) {
        $stmt = $pdo->prepare(
            'SELECT FormationID, MembershipNumber, Role FROM ' . qi('FormationAssignment')
            . ' WHERE FormationID = :formation AND MembershipNumber = :member'
        );
        $stmt->execute(['formation' => $editFormationId, 'member' => $editMembershipNumber]);
        $editRow = $stmt->fetch() ?: [];
        if ($editRow === []) {
            throw new RuntimeException('The selected formation assignment was not found.');
        }
    }

    $assignments = $pdo->query(
        'SELECT fa.FormationID, fa.MembershipNumber, fa.Role,'
        . " CONCAT(cm.FirstName, ' ', cm.LastName) AS MemberName, cm.Gender,"
        . ' t.TeamName, s.SessionDateTime, s.SessionType'
        . ' FROM ' . qi('FormationAssignment') . ' fa'
        . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = fa.MembershipNumber'
        . ' JOIN ' . qi('TeamFormation') . ' tf ON tf.FormationID = fa.FormationID'
        . ' JOIN ' . qi('Team') . ' t ON t.TeamID = tf.TeamID'
        . ' JOIN ' . qi('Session') . ' s ON s.SessionID = tf.SessionID'
        . ' ORDER BY s.SessionDateTime DESC, t.TeamName, cm.LastName, cm.FirstName LIMIT 250'
    )->fetchAll();
    ?>
<?php if (!$enforcePayment): ?>
<div class="notice warning">Payment eligibility is currently shown as a warning rather than a blocking rule because the supplied sample data has 2026 formations but mostly 2025 payments. Set <code>enforce_payment_eligibility_on_assignment</code> to <code>true</code> after the group confirms its renewal policy.</div>
<?php endif; ?>
<div class="split">
<section class="card sticky-card">
    <div class="card-header"><h2><?= $editing ? 'Edit assignment' : 'Assign club member' ?></h2><?php if ($editing): ?><a class="button secondary" href="<?= e(build_url(['page' => 'assignments'])) ?>">Cancel</a><?php endif; ?></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $editing ? 'assignment_update' : 'assignment_create' ?>">
        <input type="hidden" name="return_page" value="assignments">
        <?php if ($editing): ?><input type="hidden" name="old_formation_id" value="<?= e($editRow['FormationID']) ?>"><input type="hidden" name="old_membership_number" value="<?= e($editRow['MembershipNumber']) ?>"><?php endif; ?>
        <div class="field">
            <label>Formation *</label>
            <select name="formation_id" required><option value="">— Select —</option><?php foreach ($formations as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['FormationID'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field" style="margin-top:13px">
            <label>Club member *</label>
            <select name="membership_number" required><option value="">— Select —</option><?php foreach ($members as $option): ?><option value="<?= e($option['option_value']) ?>"<?= (string)($editRow['MembershipNumber'] ?? '') === (string)$option['option_value'] ? ' selected' : '' ?>><?= e($option['option_label']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field" style="margin-top:13px">
            <label>Role *</label>
            <select name="role" required><option value="">— Select —</option><?php foreach ($roles as $role): ?><option value="<?= e($role) ?>"<?= (string)($editRow['Role'] ?? '') === $role ? ' selected' : '' ?>><?= e($role) ?></option><?php endforeach; ?></select>
        </div>
        <button style="margin-top:17px" type="submit"><?= $editing ? 'Save assignment' : 'Assign member' ?></button>
    </form>
    <hr style="border:0;border-top:1px solid var(--border);margin:22px 0">
    <a class="button secondary" href="<?= e(build_url(['page' => 'integrity'])) ?>">Install/test conflict trigger</a>
</section>
<section class="card">
    <div class="card-header"><div><h2>Current assignments</h2><div class="muted small">Latest 250 assignments.</div></div><a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'FormationAssignment'])) ?>">Raw table editor</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Date/time</th><th>Team</th><th>Type</th><th>Member</th><th>Gender</th><th>Role</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $row): ?>
            <tr>
                <td><?= e($row['SessionDateTime']) ?></td><td><?= e($row['TeamName']) ?></td><td><?= e($row['SessionType']) ?></td>
                <td>#<?= e($row['MembershipNumber']) ?> · <?= e($row['MemberName']) ?></td><td><?= e($row['Gender']) ?></td><td><?= e($row['Role']) ?></td>
                <td class="actions">
                    <a class="button secondary small-button" href="<?= e(build_url(['page' => 'assignments', 'edit_formation' => $row['FormationID'], 'edit_member' => $row['MembershipNumber']])) ?>">Edit</a>
                    <form class="inline" method="post" data-confirm="Delete this formation assignment?">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assignment_delete"><input type="hidden" name="return_page" value="assignments">
                        <input type="hidden" name="formation_id" value="<?= e($row['FormationID']) ?>"><input type="hidden" name="membership_number" value="<?= e($row['MembershipNumber']) ?>">
                        <button class="danger small-button" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
</div>
<?php
}

function render_payments_page(PDO $pdo): void
{
    page_heading('Payments', 'Record a payment installment and review membership-year totals, required fees, and possible donations.');
    foreach (['Payment', 'ClubMember'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    $members = member_options($pdo);
    $payments = $pdo->query(
        'SELECT p.PaymentID, p.MembershipNumber, p.PaymentDate, p.Amount, p.Method, p.MembershipYear, p.InstallmentNumber,'
        . " CONCAT(cm.FirstName, ' ', cm.LastName) AS MemberName, cm.DOB,"
        . ' totals.TotalPaid'
        . ' FROM ' . qi('Payment') . ' p'
        . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = p.MembershipNumber'
        . ' JOIN (SELECT MembershipNumber, MembershipYear, SUM(Amount) AS TotalPaid FROM ' . qi('Payment') . ' GROUP BY MembershipNumber, MembershipYear) totals'
        . '   ON totals.MembershipNumber = p.MembershipNumber AND totals.MembershipYear = p.MembershipYear'
        . ' ORDER BY p.PaymentDate DESC, p.PaymentID DESC LIMIT 250'
    )->fetchAll();
    ?>
<div class="split">
<section class="card sticky-card">
    <h2>Record payment</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="payment_create"><input type="hidden" name="return_page" value="payments">
        <div class="field"><label>Club member *</label><select name="membership_number" required><option value="">— Select —</option><?php foreach ($members as $option): ?><option value="<?= e($option['option_value']) ?>"><?= e($option['option_label']) ?></option><?php endforeach; ?></select></div>
        <div class="form-grid" style="margin-top:13px">
            <div class="field"><label>Payment date *</label><input type="date" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required></div>
            <div class="field"><label>Amount *</label><input type="number" min="0.01" step="0.01" name="amount" required></div>
            <div class="field"><label>Method *</label><select name="method" required><option>Cash</option><option>Debit</option><option>Credit card</option></select></div>
            <div class="field"><label>Membership year *</label><input type="number" min="2000" max="2100" step="1" name="membership_year" value="<?= e(date('Y')) ?>" required></div>
            <div class="field"><label>Installment number *</label><select name="installment_number" required><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option></select></div>
        </div>
        <button style="margin-top:17px" type="submit">Record payment</button>
    </form>
</section>
<section class="card">
    <div class="card-header"><div><h2>Recent payments</h2><div class="muted small">A minor fee is $100 and a major fee is $200. This display estimates status using age on December 31 of the membership year; confirm that convention with the team.</div></div><a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'Payment'])) ?>">Raw table editor</a></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Member</th><th>Year</th><th>Installment</th><th>Method</th><th>Amount</th><th>Total paid</th><th>Estimated fee</th><th>Possible donation</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $row):
            $asOf = $row['MembershipYear'] . '-12-31';
            $age = age_on_date((string)$row['DOB'], $asOf);
            $fee = $age >= 18 ? 200.0 : 100.0;
            $donation = max(0, (float)$row['TotalPaid'] - $fee);
        ?>
            <tr>
                <td><?= e($row['PaymentDate']) ?></td><td>#<?= e($row['MembershipNumber']) ?> · <?= e($row['MemberName']) ?></td><td><?= e($row['MembershipYear']) ?></td><td><?= e($row['InstallmentNumber']) ?></td><td><?= e($row['Method']) ?></td>
                <td>$<?= number_format((float)$row['Amount'], 2) ?></td><td>$<?= number_format((float)$row['TotalPaid'], 2) ?></td><td>$<?= number_format($fee, 2) ?></td><td>$<?= number_format($donation, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
</div>
<?php
}

function render_reports_page(PDO $pdo, array $savedReports, string $reportSql, array $reportRows, array $reportColumns, ?string $reportError): void
{
    page_heading(
        'Reports and Query Results',
        'Run one read-only query at a time and display its result in the browser. Paste the final Q8–Q19 SQL into the saved-report array near the top of index.php.'
    );
    ?>
<div class="grid grid-2">
<section class="card">
    <h2>Q8–Q19 slots</h2>
    <?php foreach ($savedReports as $number => $report): ?>
        <details>
            <summary><?= e($number . ' — ' . $report['title']) ?> <span class="badge <?= trim((string)$report['sql']) !== '' ? 'success' : 'warning' ?>"><?= trim((string)$report['sql']) !== '' ? 'SQL added' : 'Awaiting SQL' ?></span></summary>
            <?php if (trim((string)$report['sql']) !== ''): ?>
                <pre><?= e($report['sql']) ?></pre>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="run_report"><input type="hidden" name="return_page" value="reports"><input type="hidden" name="report_sql" value="<?= e($report['sql']) ?>">
                    <button class="small-button" type="submit">Run <?= e($number) ?></button>
                </form>
            <?php else: ?>
                <p class="muted small">Add the teammate’s final SELECT statement to <code>$SAVED_REPORTS['<?= e($number) ?>']['sql']</code>.</p>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>
</section>
<section class="card">
    <h2>Read-only SQL runner</h2>
    <?php if ($reportError !== null): ?><div class="notice error"><?= e($reportError) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="run_report"><input type="hidden" name="return_page" value="reports">
        <div class="field"><label for="report_sql">SELECT query</label><textarea class="sql" id="report_sql" name="report_sql" placeholder="SELECT * FROM Location;" required><?= e($reportSql) ?></textarea><span class="hint">INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, file output, and multiple statements are blocked.</span></div>
        <button style="margin-top:14px" type="submit">Run query</button>
    </form>
</section>
</div>

<?php if ($reportSql !== '' && $reportError === null): ?>
<section class="card">
    <div class="card-header"><div><h2>Query result</h2><div class="muted small"><?= number_format(count($reportRows)) ?> displayed row(s), capped at 500.</div></div></div>
    <?php if ($reportRows === []): ?>
        <div class="empty">The query completed but returned no rows.</div>
    <?php else: ?>
    <div class="table-wrap"><table><thead><tr><?php foreach ($reportColumns as $column): ?><th><?= e($column) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($reportRows as $row): ?><tr><?php foreach ($reportColumns as $column): ?><td><?= format_cell($row[$column] ?? null) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
}

function suggested_email_range(PDO $pdo): array
{
    $today = new DateTimeImmutable('today');
    $start = $today->modify('next monday');
    $end = $start->modify('+6 days');

    if (table_exists($pdo, 'Session')) {
        $next = $pdo->query('SELECT MIN(SessionDateTime) FROM ' . qi('Session') . ' WHERE SessionDateTime >= NOW()')->fetchColumn();
        if ($next === false || $next === null) {
            $next = $pdo->query('SELECT MIN(SessionDateTime) FROM ' . qi('Session'))->fetchColumn();
        }
        if ($next !== false && $next !== null) {
            $start = new DateTimeImmutable(substr((string)$next, 0, 10));
            $end = $start->modify('+6 days');
        }
    }

    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function render_emails_page(PDO $pdo): void
{
    page_heading(
        'Email Generation and Logs',
	''
    );
    foreach (['EmailLog', 'FormationAssignment', 'TeamFormation', 'Session', 'Team', 'Location', 'Personnel', 'ClubMember'] as $table) {
        if (!table_exists($pdo, $table)) {
            echo '<div class="notice error">Missing required table: ' . e($table) . '.</div>';
            return;
        }
    }

    [$start, $end] = suggested_email_range($pdo);
    $preview = $_SESSION['generated_email_preview'] ?? [];
    unset($_SESSION['generated_email_preview']);

    $locationNameColumn = first_existing_column($pdo, 'Location', ['LocationName', 'Name']);
    $locationExpression = $locationNameColumn ? 'l.' . qi($locationNameColumn) : "CONCAT('Location #', l.LocationID)";
    $logs = $pdo->query(
        'SELECT el.EmailLogID, el.EmailDate, ' . $locationExpression . ' AS SenderLocation,'
        . " CONCAT(cm.FirstName, ' ', cm.LastName, ' (#', cm.MembershipNumber, ')') AS Receiver,"
        . ' el.Subject, el.BodyExcerpt'
        . ' FROM ' . qi('EmailLog') . ' el'
        . ' JOIN ' . qi('Location') . ' l ON l.LocationID = el.SenderLocationID'
        . ' JOIN ' . qi('ClubMember') . ' cm ON cm.MembershipNumber = el.ReceiverMembershipNumber'
        . ' ORDER BY el.EmailDate DESC, el.EmailLogID DESC LIMIT 250'
    )->fetchAll();
    ?>
<div class="grid grid-2">
<section class="card">
    <h2>Generate session notices</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="generate_emails"><input type="hidden" name="return_page" value="emails">
        <div class="form-grid">
            <div class="field"><label>Start date *</label><input type="date" name="start_date" value="<?= e($start) ?>" required></div>
            <div class="field"><label>End date *</label><input type="date" name="end_date" value="<?= e($end) ?>" required></div>
            <div class="field full"><label><input style="width:auto;min-height:auto" type="checkbox" name="skip_duplicates" value="1" checked> Skip a row when duplicate email already exist</label></div>
        </div>
        <button style="margin-top:14px" type="submit">Generate and log emails</button>
    </form>
</section>
<section class="card">
    <h2>Body contents generated</h2>
    <ul>
        <li>Club member’s first name, last name, and formation role</li>
        <li>Head coach’s first name, last name, and email address</li>
        <li>Whether the session is training or a game</li>
        <li>Session date, time, address, and team name in the subject/body</li>
    </ul>
    <a class="button secondary" href="<?= e(build_url(['page' => 'table', 'table' => 'EmailLog'])) ?>">Raw EmailLog editor</a>
</section>
</div>

<?php if (is_array($preview) && $preview !== []): ?>
<section class="card">
    <h2>Generated email preview</h2>
    <?php foreach ($preview as $email): ?>
        <details>
            <summary><?= e($email['subject']) ?></summary>
            <p><strong>From:</strong> <?= e($email['sender']) ?><br><strong>To:</strong> <?= e($email['receiver']) ?></p>
            <p><?= e($email['body']) ?></p>
        </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <div class="card-header"><div><h2>EmailLog</h2><div class="muted small">Latest 250 generated logs.</div></div></div>
    <div class="table-wrap"><table><thead><tr><th>ID</th><th>Generated</th><th>Sender location</th><th>Receiver</th><th>Subject</th><th>First 100 body characters</th></tr></thead><tbody>
    <?php foreach ($logs as $row): ?><tr><td><?= e($row['EmailLogID']) ?></td><td><?= e($row['EmailDate']) ?></td><td><?= e($row['SenderLocation']) ?></td><td><?= e($row['Receiver']) ?></td><td><?= e($row['Subject']) ?></td><td><?= e($row['BodyExcerpt']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php
}

function render_integrity_page(PDO $pdo): void
{
    page_heading(
        'Scheduling Conflict Check',
        'A player cannot be assigned to sessions less than three hours apart on the same day.'
    );

    $triggerRows = [];
    if (table_exists($pdo, 'FormationAssignment')) {
        $stmt = $pdo->prepare(
            "SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'FormationAssignment'
             ORDER BY TRIGGER_NAME"
        );
        $stmt->execute();
        $triggerRows = $stmt->fetchAll();
    }

    $requiredTriggers = [
        'trg_formation_assignment_conflict_bi',
        'trg_formation_assignment_conflict_bu',
    ];

    $installedTriggers = array_column(
        $triggerRows,
        'TRIGGER_NAME'
    );

    $protectionActive = count(
        array_diff($requiredTriggers, $installedTriggers)
    ) === 0;
    ?>
<section class="card">
    <div class="card-header">
        <div>
            <h2>Player scheduling protection</h2>

            <div class="muted small">
                Prevents conflicting team-formation assignments.
            </div>
        </div>

        <span class="badge <?= $protectionActive ? 'success' : 'warning' ?>">
            <?= $protectionActive ? 'Active' : 'Needs setup' ?>
        </span>
    </div>

    <p>
        When a player already has a session on a particular date,
        the system rejects another assignment on that date when the
        two start times are less than three hours apart.
    </p>

    <?php if ($protectionActive): ?>

        <form method="post">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="integrity_test"
            >

            <input
                type="hidden"
                name="return_page"
                value="integrity"
            >

            <button type="submit">
                Test scheduling rule
            </button>
        </form>

        <p class="muted small" style="margin-top:12px">
            The test attempts a temporary assignment one hour apart.
            All temporary test data is removed afterward.
        </p>

    <?php else: ?>

        <div class="notice warning">
            Scheduling protection must be enabled before the rule can
            be tested.
        </div>

        <form
            method="post"
            data-confirm="Enable the three-hour scheduling rule?"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="install_triggers"
            >

            <input
                type="hidden"
                name="return_page"
                value="integrity"
            >

            <button type="submit">
                Enable scheduling protection
            </button>
        </form>

    <?php endif; ?>
</section>

<section class="card">
    <details>
        <summary>
            <strong>Technical details for the project demo</strong>
        </summary>

        <p class="muted small" style="margin-top:14px">
            These details show the database triggers used for the
            scheduling rule. They can remain collapsed during normal use.
        </p>

        <?php if ($protectionActive): ?>

            <form
                method="post"
                data-confirm="Reinstall the scheduling protection rules?"
                style="margin-bottom:20px"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="install_triggers"
                >

                <input
                    type="hidden"
                    name="return_page"
                    value="integrity"
                >

                <button type="submit" class="secondary">
                    Refresh scheduling protection
                </button>
            </form>

        <?php endif; ?>

        <h3>Installed database checks</h3>

        <?php if ($triggerRows === []): ?>

            <div class="empty">
                No scheduling triggers are currently installed.
            </div>

        <?php else: ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Check</th>
                            <th>Runs</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($triggerRows as $row): ?>
                            <?php
                            $checkLabel =
                                $row['EVENT_MANIPULATION'] === 'INSERT'
                                    ? 'New assignments'
                                    : 'Edited assignments';

                            $runLabel =
                                ucfirst(
                                    strtolower(
                                        (string)$row['ACTION_TIMING']
                                    )
                                )
                                . ' '
                                . strtolower(
                                    (string)$row['EVENT_MANIPULATION']
                                );
                            ?>

                            <tr>
                                <td>
                                    <strong><?= e($checkLabel) ?></strong>

                                    <div class="muted small">
                                        <code>
                                            <?= e($row['TRIGGER_NAME']) ?>
                                        </code>
                                    </div>
                                </td>

                                <td><?= e($runLabel) ?></td>

                                <td>
                                    <span class="badge success">
                                        Installed
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

        <h3 style="margin-top:22px">Trigger SQL</h3>

        <?php foreach (trigger_sql_statements() as $name => $sql): ?>
            <details>
                <summary><?= e($name) ?></summary>
                <pre><?= e($sql) ?></pre>
            </details>
        <?php endforeach; ?>
    </details>
</section>

<?php
}

function render_schema_page(PDO $pdo): void
{
    page_heading('Schema Browser', 'Review all tables, row counts, primary keys, foreign keys, and column definitions in the connected database.');
    $tables = database_tables($pdo);
    ?>
<section class="card">
    <div class="table-wrap"><table><thead><tr><th>Table</th><th>Rows</th><th>Primary key</th><th>Foreign keys</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($tables as $table):
        $pk = primary_key_columns($pdo, $table);
        $fks = foreign_keys($pdo, $table);
        $fkLabels = [];
        foreach ($fks as $column => $fk) { $fkLabels[] = $column . ' → ' . $fk['table'] . '.' . $fk['column']; }
    ?>
        <tr>
            <td><strong><?= e($table) ?></strong></td><td><?= number_format(table_row_count($pdo, $table)) ?></td><td><?= e($pk === [] ? '—' : implode(', ', $pk)) ?></td><td><?= e($fkLabels === [] ? '—' : implode('; ', $fkLabels)) ?></td>
            <td><a class="button secondary small-button" href="<?= e(build_url(['page' => 'table', 'table' => $table])) ?>">Open table</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<section class="card">
    <h2>Column definitions</h2>
    <?php foreach ($tables as $table): ?>
        <details>
            <summary><?= e($table) ?></summary>
            <div class="table-wrap" style="margin-top:10px"><table><thead><tr><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>
            <?php foreach (table_columns($pdo, $table) as $column): ?><tr><td><?= e($column['Field']) ?></td><td><?= e($column['Type']) ?></td><td><?= e($column['Null']) ?></td><td><?= e($column['Key']) ?></td><td><?= format_cell($column['Default']) ?></td><td><?= e($column['Extra']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </details>
    <?php endforeach; ?>
</section>
<?php
}


$page = (string)($_GET['page'] ?? 'dashboard');
$currentTable = $page === 'table' ? (string)($_GET['table'] ?? '') : null;
$titleMap = [
    'dashboard' => 'Dashboard',
    'table' => $currentTable ? humanize($currentTable) : 'Table',
    'personnel' => 'Personnel',
    'family_members' => 'Family Members',
    'club_members' => 'Club Members',
    'formations' => 'Sessions and Team Formations',
    'assignments' => 'Formation Assignments',
    'payments' => 'Payments',
    'reports' => 'Reports',
    'emails' => 'Email Generation',
    'integrity' => 'Scheduling Rules',
    'schema' => 'Schema Browser',
];
$title = $titleMap[$page] ?? 'Dashboard';

render_page_header($title, $page, $currentTable, $pdo, $DB);
render_flashes(pull_flashes());

if (!$pdo instanceof PDO) {
    render_connection_error((string)$connectionError);
    render_page_footer();
    exit;
}

try {
    switch ($page) {
        case 'dashboard':
            render_dashboard($pdo, $DB);
            break;
        case 'table':
            $table = (string)($_GET['table'] ?? '');
            render_table_page($pdo, $table, (int)$APP['rows_per_table']);
            break;
        case 'personnel':
            render_personnel_page($pdo);
            break;
        case 'family_members':
            render_family_members_page($pdo);
            break;
        case 'club_members':
            render_club_members_page($pdo);
            break;
        case 'formations':
            render_formations_page($pdo);
            break;
        case 'assignments':
            render_assignments_page($pdo, (bool)$APP['enforce_payment_eligibility_on_assignment']);
            break;
        case 'payments':
            render_payments_page($pdo);
            break;
        case 'reports':
            render_reports_page($pdo, $SAVED_REPORTS, $reportSql, $reportRows, $reportColumns, $reportError);
            break;
        case 'emails':
            render_emails_page($pdo);
            break;
        case 'integrity':
            render_integrity_page($pdo);
            break;
        case 'schema':
            render_schema_page($pdo);
            break;
        default:
            http_response_code(404);
            page_heading('Page not found', 'The requested GUI page does not exist.');
            echo '<div class="card"><a class="button" href="' . e(build_url(['page' => 'dashboard'])) . '">Return to dashboard</a></div>';
    }
} catch (Throwable $exception) {
    echo '<div class="notice error"><strong>Page error:</strong> ' . e($exception->getMessage()) . '</div>';
    echo '<div class="card"><p>The current database schema may differ from the table/column names used by this workflow. Use the Schema page to compare it with the final team schema.</p></div>';
}

render_page_footer();