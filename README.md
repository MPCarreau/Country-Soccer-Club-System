# Country Soccer Club System (CSCS)

A database-driven web application. The system is designed to manage the operations of a multi-location soccer club, including club members, family members, personnel, teams, training sessions, games, payments, and player participation.

## Contributions

**Micah — SQL Implementation & Data Population**

- Designed, created, and modified the database tables required for the system.
- Implemented database constraints, including:
  - Primary keys (PK)
  - Foreign keys (FK)
  - UNIQUE constraints
  - CHECK constraints
- Created all SQL `INSERT` statements.
- Populated the database with sufficient representative data to support the required queries.
- Implemented SQL operations to create, delete, edit, and display:
  - Locations
  - Personnel
  - Family members (Primary/Secondary)
  - Club members (Major/Minor)
  - Team formations
- Implemented SQL operations for assigning, editing, and removing club members from team formations.
- Implemented SQL statements for recording club member payments.

## Overview

The **Country Soccer Club System (CSCS)** provides a centralized relational database for managing soccer club information across a head location and multiple branches.

The application supports:

* Club member registration and management for both minor and major members
* Family member and guardian relationships
* Personnel, coaches, and location assignments
* Soccer teams and team formations
* Training and game session scheduling
* Player roles and formation assignments
* FIFA game participation and results
* Annual membership payments and membership status
* Club locations and their personnel
* Automated session email notifications and email logging
* Enforcement of scheduling and database integrity constraints
* SQL-based reports and queries for club administration

## Database Design

The project includes:

* Entity-Relationship (E/R) modeling
* Relational database schema design
* Primary and foreign key constraints
* Referential and integrity constraints
* Functional dependency analysis
* Third Normal Form (**3NF**) normalization
* Boyce-Codd Normal Form (**BCNF**) analysis
* SQL triggers for enforcing business rules
* Representative test data
* Complex SQL queries and administrative reports

## Web Application

A web-based graphical user interface provides access to the database and allows users to perform common administrative operations such as creating, editing, deleting, and viewing club records.

The interface works directly with the relational database to provide a more accessible way of managing club information and executing database operations.

## Key Business Rules

The database enforces several requirements of the soccer club system, including:

* Club members must be at least 4 years old when registering.
* Minor members must be associated with a registered family member.
* Membership numbers are unique throughout the entire club system.
* Personnel can operate at only one location at a given time.
* Players on the same team must belong to the appropriate club location.
* Boys' and girls' team formations cannot be mixed.
* A player assigned to multiple formations on the same day must have at least three hours between sessions.
* Membership payment information is maintained to determine whether members are active or inactive.
* Scheduled sessions generate notification information that can be recorded in an email log.

## Purpose

This project demonstrates the design and implementation of a complete relational database application, from conceptual E/R modeling and normalization through SQL implementation, integrity enforcement, complex querying, and integration with a web-based user interface.
