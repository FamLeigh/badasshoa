-- Migration 063: Bellair Condominium Association By-Laws
-- Source: Amended and Restated By-Laws of Bellair Condominium Association, Inc.
-- Executed: November 11, 2002 | Recorded: Volusia County Public Records, Book 4963
-- Governing statute: Florida Condominium Act, Chapter 718
-- Run once against the Bellair association only.

SET @aid = (SELECT id FROM associations WHERE name LIKE '%Bellair%' LIMIT 1);

-- Ensure bylaw categories exist (INSERT IGNORE is idempotent)
INSERT IGNORE INTO rule_categories (association_id, name, sort_order) VALUES
  (@aid, 'Identity & Structure', 5),
  (@aid, 'Members',              15),
  (@aid, 'Meetings & Voting',    25),
  (@aid, 'Directors',            35),
  (@aid, 'Powers & Duties',      45),
  (@aid, 'Officers',             55),
  (@aid, 'Fiscal',               65),
  (@aid, 'Amendments',           75),
  (@aid, 'Legal Compliance',     85);

INSERT INTO rules (association_id, title, body, category, source, rule_number, effective_date) VALUES

-- ============================================================
-- SECTION 1: IDENTITY
-- ============================================================
(@aid, 'Identity',
'These are the By-Laws of BELLAIR CONDOMINIUM ASSOCIATION, INC. (the "Association"), a corporation not for profit incorporated under the laws of the State of Florida, organized for the purpose of administering the BELLAIR CONDOMINIUM (the "Condominium") located in Volusia County, Florida.',
'Identity & Structure', 'bylaw', '1', '2002-11-11'),

(@aid, 'Principal Office',
'The principal office of the Association shall be 2727 North Atlantic Avenue, Daytona Beach, Florida 32118, or at such other place as may be designated by the Board of Directors from time to time.',
'Identity & Structure', 'bylaw', '1.1', '2002-11-11'),

(@aid, 'Seal',
'The seal of the Association shall bear the name of the corporation, the word "Florida," the words "Corporation not for Profit," and the year of incorporation.',
'Identity & Structure', 'bylaw', '1.2', '2002-11-11'),

-- ============================================================
-- SECTION 2: DEFINITIONS
-- ============================================================
(@aid, 'Definitions',
'The terms used herein shall have the same definitions as stated in the Florida Condominium Act and the Declaration of Condominium to which these By-Laws are attached as an Exhibit.',
'Identity & Structure', 'bylaw', '2', '2002-11-11'),

-- ============================================================
-- SECTION 3: MEMBERS
-- ============================================================
(@aid, 'Members',
'The members of the Association shall be the record Owners of fee title to the Units. In the case of a Unit subject to an agreement for deed, the purchaser in possession shall be deemed the Owner of the Unit solely for purposes of determining voting and use rights.',
'Members', 'bylaw', '3', '2002-11-11'),

(@aid, 'Member Qualifications',
'Membership shall become effective upon the recording in the Public Records of a Deed or other instrument evidencing legal title to the Unit in the member.',
'Members', 'bylaw', '3.1', '2002-11-11'),

(@aid, 'Voting Rights; Voting Interests',
'The members of the Association are entitled to one (1) vote for each Unit owned by them. The total number of votes ("voting interests") is equal to the total number of Units. The vote of a Unit is not divisible. The right to vote may not be denied because of delinquent assessments.\n\nIf a Unit is owned by one natural person, individually or as trustee, his right to vote shall be established by the record title to the Unit. If a Unit is owned jointly by two or more persons, that Unit''s vote may be cast by any of the owners; however, if two or more owners of a Unit do not agree among themselves how their one vote shall be cast, that vote shall not be counted. If the owner of a Unit is a corporation, the vote of that Unit may be cast by any officer of the corporation. If a Unit is owned by a partnership, its vote may be cast by a general partner.',
'Members', 'bylaw', '3.2', '2002-11-11'),

(@aid, 'Approval or Disapproval of Matters',
'Whenever the decision of a Unit owner is required upon any matter, whether or not the subject of an Association meeting, such decision may be expressed by any person authorized to cast the vote of such Unit at an Association meeting as stated in Section 3.2 above, unless the joinder of all owners is specifically required.',
'Members', 'bylaw', '3.3', '2002-11-11'),

(@aid, 'Termination of Membership',
'The termination of membership in the Association does not relieve or release any former members from liability or obligation incurred under or in any way connected with the Condominium during the period of his membership, nor does it impair any rights or remedies which the Association may have against any former member arising out of or in any way connected with such membership and the covenants and obligations incident thereto.',
'Members', 'bylaw', '3.4', '2002-11-11'),

-- ============================================================
-- SECTION 4: MEMBERS' MEETINGS; VOTING
-- ============================================================
(@aid, 'Annual Meeting',
'The annual members'' meeting shall be held on the date, at the place and at the time during the month of January or February as determined by the Board of Directors from time to time, provided that there shall be an annual meeting every calendar year and, to the extent possible, no later than twelve (12) months after the last preceding annual meeting. The purpose of the meeting shall be to elect Directors and to transact any other business authorized to be transacted by the members.',
'Meetings & Voting', 'bylaw', '4.1', '2002-11-11'),

(@aid, 'Special Meetings of Members',
'Special members'' meetings may be called by the President, or by a majority of the Board of Directors of the Association, and must be called by the Association upon receipt of a written request from members having at least a majority of the votes of the entire membership of the Association. The business conducted at a special meeting shall be limited to that stated in the notice of the meeting.',
'Meetings & Voting', 'bylaw', '4.2', '2002-11-11'),

(@aid, 'Notice of Meeting; Waiver of Notice',
'Notice of a meeting of members stating the time and place and the purpose(s) for which the meeting is called, shall be given by the President or Secretary. The notice shall include an agenda for all known substantive matters to be discussed, or have an agenda attached to it. A copy of the notice, and agenda, shall be posted at the designated location on the Condominium property. The notice of any meeting shall be sent by mail to each Unit owner unless the Unit owner waives in writing the right to receive notice of the annual meeting by mail. The delivery or mailing shall be to the address of the member as it appears on the roster of members. The posting and mailing of the notice shall be effected not less than ten (10) days, nor more than sixty (60) days prior to the date of the meeting. Proof of notice shall be given by Affidavit.\n\nNotice of specific meetings may be waived before or after the meeting and the attendance of any member (or person authorized to vote for such member) shall constitute such member''s waiver of notice of such meeting, except when his attendance is for the express purpose of objecting at the beginning of the meeting to the transaction of business because the meeting is not lawfully called.',
'Meetings & Voting', 'bylaw', '4.3', '2002-11-11'),

(@aid, 'Quorum at Members'' Meetings',
'A quorum at members'' meeting shall be obtained by the presence, either in person or by proxy, of persons entitled to cast a majority of the votes of the members.',
'Meetings & Voting', 'bylaw', '4.4', '2002-11-11'),

(@aid, 'Voting — Majority Vote',
'The acts approved by a majority of the votes present in person or by proxy at a meeting at which a quorum shall have been attained shall be binding upon all Unit owners for all purposes, except where otherwise provided by law, the Declaration, the Articles or these By-Laws.\n\nAs used in these By-Laws, the Articles or the Declaration, the terms "majority of the Unit owners" and "majority of the members" shall mean a majority of the votes of members and not a majority of the members themselves and shall further mean more than fifty percent (50%) of the then total authorized votes present in person or by proxy and voting at any meeting of the Unit owners at which a quorum shall have been attained. Similarly, if some greater percentage of members is required herein or in the Declaration or Articles, it shall mean such greater percentage of the votes of members and not of the members themselves.',
'Meetings & Voting', 'bylaw', '4.5', '2002-11-11'),

(@aid, 'Proxies',
'Votes may be cast in person or by proxy. A proxy may be made by any person entitled to vote, but shall only be valid for the specific meeting for which originally given and any lawful adjourned meetings thereof. In no event shall any proxy be valid for a period longer than 90 days after the date of the first meeting for which it was given. Every proxy shall be revocable at any time at the pleasure of the person executing. A proxy must be filed in writing, signed by the person authorized to cast the vote for the Unit and filed with the Secretary before the appointed time of the meeting, or before the time to which the meeting is adjourned. Holders of proxies must be Unit owners, or spouses of Unit owners.\n\nUnit owners may not vote by general proxy, but may vote by use of a limited proxy substantially conforming to a limited proxy form adopted by the Division of Florida Land Sales, Condominium and Mobile Homes. Both limited proxies and general proxies may be used to establish a quorum. Limited proxies shall be used for votes taken to waive or reduce reserves; for votes taken to waive financial reporting requirements; for votes taken to amend the Declaration, the Articles of Incorporation, or By-Laws; for the election of Directors (provided that ballots shall be used by those attending the annual meeting in person); and for any other matter which the Florida Condominium Act requires or permits a vote of the Unit owners. General proxies may be used for other matters for which limited proxies are not required, and may also be used in voting for nonsubstantive changes to items for which a limited proxy is required and given.',
'Meetings & Voting', 'bylaw', '4.6', '2002-11-11'),

(@aid, 'Adjourned Meetings',
'If any proposed meeting cannot be organized because a quorum has not been attained, the members who are present, either in person or by proxy, may adjourn the meeting from time to time until a quorum is present, provided notice of the newly scheduled meeting is given in the manner required for the giving of notice of meeting.',
'Meetings & Voting', 'bylaw', '4.7', '2002-11-11'),

(@aid, 'Order of Business at Annual Meetings',
'If a quorum has been attained, to the extent desired by the Board of Directors, the order of business at annual members'' meetings shall be:\n\n(a) Call to order by President\n(b) At the discretion of the President, appointment of a chairperson of the meeting\n(c) Call for final balloting on election of Directors and close of balloting\n(d) Appointment of inspectors of election (if desired)\n(e) Election of Directors\n(f) Calling of the roll, certifying of proxies, and determination of a quorum\n(g) Proof of notice of the meeting or waiver of notice\n(h) Reading and disposal of any unapproved minutes\n(i) Treasurer''s Report\n(j) Reports of officers\n(k) Reports of committees\n(l) Vote to fully or partially fund Reserves\n(m) Vote to roll over any leftover monies\n(n) Unfinished business\n(o) New business\n(p) Adjournment\n\nSuch order may also be waived in whole or in part by direction of the President or the chairperson.',
'Meetings & Voting', 'bylaw', '4.8', '2002-11-11'),

(@aid, 'Minutes of Member Meetings',
'The minutes of all meetings of Unit owners shall be kept in a book available for inspection by Unit owners or their authorized representatives at any reasonable time. The Association shall retain these minutes for a period of not less than seven years.',
'Meetings & Voting', 'bylaw', '4.9', '2002-11-11'),

(@aid, 'Action Without a Meeting',
'To the extent lawful, any action required or permitted to be taken at any annual or special meeting of members may be taken without a meeting, without prior notice and without a vote if a consent in writing, setting forth the action so taken, shall be signed by the members having not less than the minimum number of votes that would be necessary to authorize or take such action at a meeting of members at which a quorum of members entitled to vote thereon were present and voted.\n\nIf the requisite number of written consents are received by the Secretary within sixty (60) days after the earliest date which appears on any of the consent forms received, the proposed action so authorized shall be of full force and effect as if the action had been approved by vote of the members at a meeting of the members held on the sixtieth (60th) day. With ten (10) days after obtaining such authorization by written consent, notice must be given to members who have not consented in writing. The notice shall fairly summarize the material features of the authorized action. Members may also consent in writing to actions taken at a meeting by providing a written statement to that effect and their vote shall be fully counted as though they had attended the meeting.',
'Meetings & Voting', 'bylaw', '4.10', '2002-11-11'),

-- ============================================================
-- SECTION 5: DIRECTORS
-- ============================================================
(@aid, 'Number and Terms of Service',
'The Board of Directors shall be composed of five (5) Directors until such time as the Board, at a duly convened Board meeting, formally changes the number of Directors. At subsequent annual meetings, Directors shall be elected for three (3) year terms, in a staggered fashion to result in the election of approximately one-third of the Board each year, provided that the Board, at a duly convened meeting occurring at least sixty (60) days prior to the annual election of Directors, may establish a term of one (1) or two (2) years in regard to directorship(s) to be filled at such annual election in order to preserve the proper staggering of the election of Directors. A Director''s term ends at the annual election at which his successor is to be duly elected, or at such other time as may be provided by law.',
'Directors', 'bylaw', '5.1', '2002-11-11'),

(@aid, 'Director Qualifications',
'Each Director must be a member or the spouse of a member; provided that the officers of corporate Unit owners shall be qualified to serve as Directors.',
'Directors', 'bylaw', '5.2', '2002-11-11'),

(@aid, 'Election of Directors',
'The election of Directors shall occur on the date of the annual meeting.\n\n(a) The limited proxy prepared for the annual meeting shall list all Director candidates in alphabetical order.\n(b) There shall be no quorum requirement; however, at least twenty percent (20%) of the eligible voters must send in a proxy or cast a ballot to have a valid election. Elections shall be decided by a plurality of those votes cast.\n(c) The Board of Directors may appoint a nominating committee to nominate or recommend specific persons for election to the Board, and shall generally recruit and encourage eligible persons to run as candidates for election to the Board.\n(d) Tie votes shall be broken by agreement among the candidates who are tied, or if there is no agreement, by lot, such as the flipping of a coin by a neutral party.',
'Directors', 'bylaw', '5.3', '2002-11-11'),

(@aid, 'Vacancies on the Board',
'If the office of any Director becomes vacant for any reason, a successor or successors to fill the remaining unexpired term or terms shall be appointed or elected as follows:\n\n(a) If a vacancy is caused by the death, disqualification or resignation of a Director, a majority of the remaining Directors, even if less than a quorum, shall appoint a successor, who shall hold office for the remaining unexpired term, unless otherwise required by law.\n(b) If a vacancy occurs as a result of a recall and less than a majority of the Directors are removed, the vacancy may be filled by appointment by a majority of the remaining Directors, even if less than a quorum. If vacancies occur as a result of a recall in which a majority or more of the Directors are removed, the vacancies shall be filled in accordance with procedural rules adopted by the Division of Florida Land Sales, Condominium and Mobile Homes.',
'Directors', 'bylaw', '5.4', '2002-11-11'),

(@aid, 'Removal of Directors',
'Any or all Directors may be removed with or without cause by majority vote of the entire membership, either by a written petition or at any meeting called for that purpose. If a meeting is held or a petition is filed for the removal of more than one Director, the question shall be determined separately as to each Director sought to be removed. If a special meeting is called by ten percent (10%) of the voting interests for the purpose of recall, the notice of the meeting must be accompanied by a dated copy of the signature list, stating the purpose of the signatures. The meeting must be held not less than fourteen (14) days nor more than sixty (60) days from the date that notice of the meeting is given.',
'Directors', 'bylaw', '5.5', '2002-11-11'),

(@aid, 'Organization Meeting of New Directors',
'The organizational meeting of newly-elected or appointed Directors shall be held within ten (10) days of their election or appointment at such place and time as shall be fixed by the Directors. Notice of the organizational meeting shall be posted at the designated location on the Condominium property at least forty-eight (48) continuous hours in advance of the meeting.',
'Directors', 'bylaw', '5.6', '2002-11-11'),

(@aid, 'Regular Board Meetings',
'Regular meetings of the Board of Directors shall be held at the principal office of the Association at such times as shall be determined, from time to time, by a majority of the Directors. Except as otherwise authorized by law, meetings of the Board of Directors shall be open to all Unit owners who may participate in accordance with the written policy established from time to time by the Board of Directors. Notice of such meetings shall be posted at a designated location on the Condominium property at least forty-eight (48) continuous hours in advance. All notices shall include an agenda for all known substantive matters to be discussed.\n\nWritten notice of any meeting at which non-emergency special assessments, or at which amendment to rules regarding Unit use will be considered, shall be mailed or delivered to the Unit owners and posted at a designated location on the Condominium property not less than fourteen (14) continuous days prior to the meeting. Evidence of compliance with this fourteen (14) day notice shall be by Affidavit by the person providing the notice, and filed among the official records of the Association.',
'Directors', 'bylaw', '5.7', '2002-11-11'),

(@aid, 'Special Meetings of the Board',
'Special meetings of the Directors may be called by the President, and must be called by the President or Secretary at the written request of one-half (1/2) of the Directors. Special meetings of the Board of Directors shall be noticed and conducted in the same manner as provided herein for regular meetings.',
'Directors', 'bylaw', '5.8', '2002-11-11'),

(@aid, 'Notice to Board Members; Waiver of Notice',
'Notice of Board meetings shall be given to Board members personally or by mail, telephone, telegraph, or by facsimile transmission which notice shall state the time, place and purpose of the meeting, and shall be transmitted not less than forty-eight (48) hours prior to the meeting. Any Director may waive notice of a meeting before or after the meeting and that waiver shall be deemed equivalent to the due receipt by said Director of notice. Attendance by any Director at a meeting shall constitute a waiver of notice of such meeting, except when attendance is for the express purpose of objecting at the beginning of the meeting to the transaction of business because the meeting is not lawfully called.',
'Directors', 'bylaw', '5.9', '2002-11-11'),

(@aid, 'Quorum at Board Meetings',
'A quorum at Directors'' meetings shall consist of a majority of the entire Board of Directors. The acts approved by a majority of those Directors present at a meeting at which a quorum is present shall constitute the acts of the Board of Directors, except when approval by a greater number of Directors is specifically required by the Declaration, the Articles or these By-Laws. Directors may vote by secret ballot only for the election of officers. At all other times, a vote or abstention for each Director present shall be recorded in the minutes. Directors may not abstain from voting except in the case of an asserted conflict of interest.',
'Directors', 'bylaw', '5.10', '2002-11-11'),

(@aid, 'Adjourned Board Meetings',
'If, at any proposed meeting of the Board of Directors, there is less than a quorum present, the majority of those present may adjourn the meeting from time to time until a quorum is present, provided notice of such newly scheduled meeting is given as required hereunder. At any newly scheduled meeting, any business that might have been transacted at the meeting as originally called may be transacted without further notice.',
'Directors', 'bylaw', '5.11', '2002-11-11'),

(@aid, 'Joinder in Meeting by Approval of Minutes',
'The subsequent joinder of an absent Director in the action of a meeting by signing and concurring in the minutes of that meeting shall constitute the approval of that Director of the business conducted at the meeting; provided, however, the joinder of a Director as aforesaid shall not be used for the purposes of creating a quorum.',
'Directors', 'bylaw', '5.12', '2002-11-11'),

(@aid, 'Presiding Officer at Board Meetings',
'The presiding officer at the Directors'' meetings shall be the President (who may, however, designate any other person to preside). In the absence of the presiding officer, the Directors present may designate any person to preside.',
'Directors', 'bylaw', '5.13', '2002-11-11'),

(@aid, 'Order of Business at Board Meetings',
'If a quorum has been attained, to the extent desired by the Board of Directors, the order of business at Directors'' meetings shall be:\n\n(a) Call to order by President\n(b) Proof of notice of the meeting or waiver of notice\n(c) Reading and disposal of any unapproved minutes\n(d) Treasurer''s report\n(e) Reports of officers\n(f) Reports of committees\n(g) Unfinished business\n(h) New business\n(i) Adjournment\n\nSuch order may be waived in whole or in part by direction of the President, or the presiding officer.',
'Directors', 'bylaw', '5.14', '2002-11-11'),

(@aid, 'Minutes of Board Meetings',
'The minutes of all meetings of the Board of Directors shall be kept in a book available for inspection by Unit owners, or their authorized representatives, at any reasonable time. The Association shall retain these minutes for a period of not less than seven (7) years.',
'Directors', 'bylaw', '5.15', '2002-11-11'),

(@aid, 'Executive Committee; Other Committees',
'The Board of Directors may, by resolution duly adopted, appoint an Executive Committee to consist of two (2) or more members of the Board of Directors. Such Executive Committee shall have and may exercise all of the powers of the Board of Directors in management of the business and affairs of the Condominium during the period between the meetings of the Board of Directors insofar as may be permitted by law, except that the Executive Committee shall not have power (a) to determine the common expenses required for the affairs of the Condominium, (b) to determine the assessments payable by the Unit owners to meet the common expenses of the Condominium, (c) to adopt or amend any rules and regulations governing the details of the operation and use of the Condominium property, (d) to fill vacancies on the Board of Directors or (e) to exercise any of the powers set forth in paragraphs (g) and (p) of Section 6 of these By-Laws.\n\nThe Board of Directors may by resolution create other committees and may invest in such committees such powers and responsibilities as the Board shall deem advisable. The Board may authorize the President to appoint committee members, and its Chairman. Committees authorized to take action on behalf of the Board, or to make recommendations to the Board regarding the Association budget, shall conduct their affairs in the same manner as provided in these By-Laws for Board of Director meetings. All other committees may meet and conduct their affairs in private without prior notice or owner participation.',
'Directors', 'bylaw', '5.16', '2002-11-11'),

-- ============================================================
-- SECTION 6: POWERS AND DUTIES
-- ============================================================
(@aid, 'Powers and Duties — Operating Common Elements',
'Operating and maintaining the Common Elements.',
'Powers & Duties', 'bylaw', '6(a)', '2002-11-11'),

(@aid, 'Powers and Duties — Determining Common Expenses',
'Determining the common expenses required for the operation of the Condominium and the Association.',
'Powers & Duties', 'bylaw', '6(b)', '2002-11-11'),

(@aid, 'Powers and Duties — Collecting Assessments',
'Collecting the assessments for common expenses from Unit owners.',
'Powers & Duties', 'bylaw', '6(c)', '2002-11-11'),

(@aid, 'Powers and Duties — Personnel and Management',
'Employing and dismissing the personnel necessary for the maintenance and operation of the Common Elements, including the authority to engage and demiss a manager for the Condominium.',
'Powers & Duties', 'bylaw', '6(d)', '2002-11-11'),

(@aid, 'Powers and Duties — Adopting Rules and Regulations',
'Adopting and amending rules and regulations concerning the operation and use of the Condominium property.',
'Powers & Duties', 'bylaw', '6(e)', '2002-11-11'),

(@aid, 'Powers and Duties — Bank Accounts',
'Maintaining accounts at depositories on behalf of the Association and designating the signatories required therefor.',
'Powers & Duties', 'bylaw', '6(f)', '2002-11-11'),

(@aid, 'Powers and Duties — Acquiring Units or Property',
'Purchasing, leasing or otherwise acquiring Units or other property in the name of the Association, or its designee.',
'Powers & Duties', 'bylaw', '6(g)', '2002-11-11'),

(@aid, 'Powers and Duties — Foreclosure Purchases',
'Purchasing Units at foreclosure or other judicial sales, in the name of the Association, or its designee.',
'Powers & Duties', 'bylaw', '6(h)', '2002-11-11'),

(@aid, 'Powers and Duties — Selling or Leasing Acquired Units',
'Selling, leasing, mortgaging or otherwise dealing with Units acquired, and subleasing Units leased, by the Association, or its designee.',
'Powers & Duties', 'bylaw', '6(i)', '2002-11-11'),

(@aid, 'Powers and Duties — Organizing Designee Entities',
'Organizing corporation and appointing persons to act as designees of the Association in acquiring title to or leasing Units or other property.',
'Powers & Duties', 'bylaw', '6(j)', '2002-11-11'),

(@aid, 'Powers and Duties — Insurance',
'Obtaining and reviewing insurance for the Condominium property.',
'Powers & Duties', 'bylaw', '6(k)', '2002-11-11'),

(@aid, 'Powers and Duties — Repairs and Improvements',
'Making repairs, additions and improvements to, or alterations of, the Condominium property, and repairs to and restoration of the Condominium property, in accordance with the provisions of the Declaration after damage or destruction by fire or other casualty, or as a result of condemnation or eminent domain proceedings or otherwise.',
'Powers & Duties', 'bylaw', '6(l)', '2002-11-11'),

(@aid, 'Powers and Duties — Enforcing Obligations',
'Enforcing obligations of the Unit owners, allocating profits and expenses and taking such other actions as shall be deemed necessary and proper for the sound management of the Condominium.',
'Powers & Duties', 'bylaw', '6(m)', '2002-11-11'),

(@aid, 'Powers and Duties — Levying Fines',
'Levying fines against Unit owners for violations of the rules and regulations established by the Association to govern the conduct of occupants at the Condominium. The Board of Directors may levy a fine against a Unit owner, not to exceed the maximum amount permitted by law, for each violation by the owner, or his or her tenants, guests or visitors, of the Declaration, Articles, By-Laws, or rules or regulations, and a separate fine for each repeat or continued violation; provided, however, written notice of the nature of the violation and an opportunity to attend a hearing shall be given prior to the levy of the initial fine. No written notice or hearing shall be necessary for the levy of a separate fine for repeat or continued violations if substantially similar to the initial violation for which notice and a hearing was provided. The Board of Directors shall have the authority to adopt rules, regulations and policies to fully implement its fining authority.\n\nThe party against whom the fine is sought to be levied shall be afforded an opportunity for hearing after reasonable notice of not less than fourteen (14) days. The notice shall include: (1) a statement of the date, time and place of the hearing; (2) a statement of the provisions of the Declaration, Association By-Laws, or Association Rules which have allegedly been violated; and (3) a short and plain statement of the matters asserted by the Association.\n\nThe party against whom the fine may be levied shall have an opportunity to respond, to present evidence, and to provide written and oral argument on all issues involved and shall have an opportunity at the hearing to review, challenge, and respond to any material considered by the Association. The hearing shall be conducted before a panel of three (3) Unit owners appointed by the Board, who, to the extent possible, shall not then be serving as Directors. If the panel, by majority vote, does not agree with the fine, it may not be levied.',
'Powers & Duties', 'bylaw', '6(n)', '2002-11-11'),

(@aid, 'Powers and Duties — Superintendent Housing',
'Purchasing or leasing Units for use by resident superintendents and other similar persons.',
'Powers & Duties', 'bylaw', '6(o)', '2002-11-11'),

(@aid, 'Powers and Duties — Borrowing Money',
'Borrowing money on behalf of the Condominium when required in connection with the operation, care, upkeep and maintenance of the Common Elements or the acquisition of property, and granting mortgages and/or security interests in Association owned property; provided, however, that the consent of the owners of at least two-thirds (2/3rds) of the Units represented at a meeting at which a quorum has been attained shall be required for the borrowing of any sum in excess of Ten Thousand Dollars ($10,000.00). If any sum borrowed by the Board of Directors on behalf of the Condominium pursuant to the authority contained in this subparagraph is not repaid by the Association, a Unit owner who pays to the creditor such portion thereof as his interest in the Common Elements bears to the interest of all the Unit owners in the Common Elements shall be entitled to obtain from the creditor a release of any judgment or other lien which said creditor shall have filed or shall have the right to file against such Unit owner''s Unit.',
'Powers & Duties', 'bylaw', '6(p)', '2002-11-11'),

(@aid, 'Powers and Duties — Management Contracts',
'Contracting for the management and maintenance of the Condominium property and authorizing a management agent to assist the Association in carrying out its powers and duties by performing such functions as the submission of proposals, collection of assessments, preparation of records, enforcement of rules and maintenance, repair, and replacement of the Common Elements with such funds as shall be made available by the Association for such purposes. The Association and its officers shall, however, retain at all times the powers and duties granted by the Condominium documents and the Act, including the making of assessments, promulgation of rules and execution of contracts on behalf of the Association.\n\nAll contracts for the purchase, lease or renting of materials or equipment, all contracts for services, and any contract that is not to be fully performed within one year, shall be in writing. For so long as required by law, the Association shall obtain competitive bids for any contract which requires payment exceeding five percent (5%) of the total annual budget of the Association (except for contracts with employees of the Association, attorneys, accountants, managers or management companies, architects, engineers, or landscape engineers), unless the products and services are needed in the result of any emergency or unless the desired supplier is the only source of supply within the county serving the Association. The Board need not accept the lowest bid.',
'Powers & Duties', 'bylaw', '6(q)', '2002-11-11'),

(@aid, 'Powers and Duties — Private Use of Common Elements',
'At its discretion, authorizing Unit owners or other persons to use portions of the Common Elements for private parties and gatherings and imposing reasonable charges for such private use.',
'Powers & Duties', 'bylaw', '6(r)', '2002-11-11'),

(@aid, 'Powers and Duties — General Corporate Powers',
'Exercising (i) all powers specifically set forth in the Declaration, the Articles, these By-Laws and in the Act, (ii) all powers incidental thereto, and (iii) all other powers to a Florida corporation not for profit.',
'Powers & Duties', 'bylaw', '6(s)', '2002-11-11'),

(@aid, 'Powers and Duties — Transfer Fees',
'Imposing a lawful fee in connection with the approval of the transfer or sale of Units, not to exceed the maximum amount permitted by law in any one case.',
'Powers & Duties', 'bylaw', '6(t)', '2002-11-11'),

(@aid, 'Powers and Duties — Hurricane Shutters',
'Adopting hurricane shutter specifications for each building within the Condominium which shall include color, style, and other factors deemed relevant by the Board. All specifications adopted by the Board shall comply with the applicable building code, or shall be structured to ensure that installed shutters are in compliance with the applicable building code. The Board shall not refuse to approve the installation or replacement of hurricane shutters conforming to the specifications adopted by the Board.',
'Powers & Duties', 'bylaw', '6(u)', '2002-11-11'),

(@aid, 'Powers and Duties — On-Site Rental Program',
'Contracting for the services of an on-site rental manager and otherwise operate an on-site voluntary rental program.',
'Powers & Duties', 'bylaw', '6(v)', '2002-11-11'),

(@aid, 'Powers and Duties — Conveying Common Elements for Public Use',
'Conveying a portion of the Common Elements to a condemning authority for the purpose of providing utility easements, right-of-way expansion, or other public purposes, whether negotiated or as a result of eminent domain proceedings.',
'Powers & Duties', 'bylaw', '6(w)', '2002-11-11'),

-- ============================================================
-- SECTION 7: EMERGENCY BOARD POWERS
-- ============================================================
(@aid, 'Emergency Board Powers',
'In the event of any "emergency" as defined below, the Board of Directors may exercise the following emergency powers:\n\n(a) The Board may name as assistant officers persons who are not Directors, which assistant officers shall have the same authority as the executive officers to whom they are assistant during the period of the emergency, to accommodate the incapacity of any officer of the Association.\n(b) The Board may relocate the principal office or designate alternative principal offices or authorize the officers to do so.\n(c) During any emergency the Board may hold meetings with notice given only to those Directors with whom it is practicable to communicate, and the notice may be given in any practicable manner, including publication or radio. The Director or Directors in attendance at such a meeting shall constitute a quorum.\n(d) Corporate action taken in good faith during an emergency under this Section to further the ordinary affairs of the Association shall bind the Association, and shall have the rebuttable presumption of being reasonable and necessary.\n(e) Any officer, director, or employee of the Association acting with a reasonable belief that his actions are lawful in accordance with these emergency By-Laws shall incur no liability for doing so, except in the case of willful misconduct.\n(f) These emergency By-Laws shall supersede any inconsistent or contrary provisions of the By-Laws during the period of the emergency.\n(g) An "emergency" exists only during a period of time that the Condominium, or the immediate geographic area in which the Condominium is located, is subjected to: (1) a state of emergency declared by local civil or law enforcement authorities; (2) a hurricane warning; (3) a partial or complete evacuation order; (4) federal or state "disaster area" status; or (5) a catastrophic occurrence, whether natural or manmade, which seriously damages or threatens to seriously damage the physical existence of the Condominium, such as an earthquake, tidal wave, fire, hurricane, tornado, war, civil unrest, or act of terrorism. An "emergency" also exists during the time when a quorum of the Board cannot readily be assembled because of the occurrence of a catastrophic event. A determination by any two (2) Directors, or by the President, that an emergency exists shall have presumptive quality.',
'Powers & Duties', 'bylaw', '7', '2002-11-11'),

-- ============================================================
-- SECTION 8: OFFICERS
-- ============================================================
(@aid, 'Executive Officers',
'The executive officers of the Association shall be a President, Vice President, a Treasurer and a Secretary (only the President must be a Director). All officers shall be elected by the Board of Directors and may be peremptorily removed at any meeting by concurrence of a majority of all of the Directors. A person may hold more than one (1) office, except that the President may not also be the Vice-President, Secretary or Treasurer. No person shall sign an instrument or perform an act in the capacity of more than one office. The Board of Directors from time to time may elect such other officers and designate their powers and duties as the Board shall deem necessary or appropriate to manage the affairs of the Association.',
'Officers', 'bylaw', '8.1', '2002-11-11'),

(@aid, 'President',
'The President shall be the chief executive officer of the Association. He shall have all of the powers and duties that are usually vested in the office of president of an association, including the power to appoint committees.',
'Officers', 'bylaw', '8.2', '2002-11-11'),

(@aid, 'Vice President',
'The Vice President shall exercise the powers and perform the duties of the President in the absence or disability of the President. He also shall assist the President and exercise such other powers and perform such other duties as are incident to the office of the vice president of an association and as may be required by the Directors or the President.',
'Officers', 'bylaw', '8.3', '2002-11-11'),

(@aid, 'Secretary',
'The Secretary shall keep the minutes of all proceedings of the Directors and the members. He shall attend to the giving of all notices to the members and Directors and other notices required by law. He shall have custody of the seal of the Association and shall affix it to instruments requiring the seal when duly signed. He shall keep the records of the Association, except those of the Treasurer, and shall perform all other duties incident to the office of the Secretary of an association and as may be required by the Directors or the President.',
'Officers', 'bylaw', '8.4', '2002-11-11'),

(@aid, 'Treasurer',
'The Treasurer shall have custody of all property of the Association, including funds, securities and evidences of indebtedness. He shall keep books of account for the Association in accordance with good accounting practices, which, together with substantiating papers, shall be made available to the Board of Directors for examination at reasonable times. He shall submit a Treasurer''s report to the Board of Directors at reasonable intervals and shall perform all other duties incident to the office of treasurer as may be required by the Directors or the President. All monies and other valuable effects shall be kept for the benefit of the Association in such depositories as may be designated by a majority of the Board of Directors.',
'Officers', 'bylaw', '8.5', '2002-11-11'),

(@aid, 'Delegation by Officers',
'The Board of Directors may delegate any or all of the functions of the Secretary or Treasurer, or both, to a management agent or employee, provided that the Secretary or Treasurer shall in such instance generally supervise the performance of the agent or employee in the performance of such functions.',
'Officers', 'bylaw', '8.6', '2002-11-11'),

-- ============================================================
-- SECTION 9: COMPENSATION
-- ============================================================
(@aid, 'Compensation of Directors and Officers',
'Neither Directors nor officers shall receive compensation for their services as such; moreover, by resolution of the Board of Directors, a fixed fee and expenses of attendance may be allowed for attendance at regular or special Board meetings.',
'Officers', 'bylaw', '9', '2002-11-11'),

-- ============================================================
-- SECTION 10: RESIGNATIONS
-- ============================================================
(@aid, 'Resignations',
'Any Director or officer may resign his post at any time by written resignation, delivered to the President or Secretary, which shall take effect upon its receipt unless a later date is specified in the resignation, in which event the resignation shall be effective from such date unless withdrawn. The acceptance of a resignation shall not be required to make it effective. The conveyance of all Units owned by any Director or officer shall constitute a written resignation of such Director or officer without need for a written resignation.',
'Officers', 'bylaw', '10', '2002-11-11'),

-- ============================================================
-- SECTION 11: FISCAL MATTERS
-- ============================================================
(@aid, 'Special Assessments',
'Special assessments may be imposed by the Board of Directors to meet unusual, unexpected, unbudgeted, or non-recurring expenses. Special assessments are due on the day specified in the resolution of the Board approving such assessments. The notice of any Board meeting at which a special assessment will be considered shall be given as provided in Section 4.3; and the notice to the owners that the assessment has been levied must contain a statement of the purpose(s) of the assessment. The funds collected must be spent for the stated purpose(s) or returned to the members as provided by law.',
'Fiscal', 'bylaw', '11.1', '2002-11-11'),

(@aid, 'Fidelity Bonds',
'The President, Secretary and Treasurer, and all other persons who are authorized to sign checks or handle Condominium funds, shall be bonded in such amounts as may be required by law, the Declaration of Condominium, or otherwise determined by the Board of Directors. The premium on such bonds is a common expense.',
'Fiscal', 'bylaw', '11.2', '2002-11-11'),

(@aid, 'Financial Reports',
'In accordance with Section 718.111(13) of the Condominium Act, not later than sixty (60) days after the close of each fiscal year, the Board shall distribute to the owners of each Unit a report of actual receipts and expenditures for the previous twelve (12) months; or, if required by Section 718.111(14), a complete set of financial statements for the preceding fiscal year prepared in accordance with generally accepted accounting principles, which shall be sent to the members within ninety (90) days after the end of the fiscal year in lieu of the financial report referenced herein.',
'Fiscal', 'bylaw', '11.3', '2002-11-11'),

(@aid, 'Fiscal Year',
'The fiscal year for the Association shall begin on the first day of January of each calendar year. The Board of Directors may adopt a different fiscal year in accordance with law and the regulations of the Internal Revenue Service.',
'Fiscal', 'bylaw', '11.4', '2002-11-11'),

(@aid, 'Depository',
'The depository of the Association shall be such bank, banks or other federally insured depository, in the State as shall be designated from time to time by the Directors and in which the monies of the Association shall be deposited not to exceed the amount of federal insurance available provided for any account. Withdrawal of monies from those accounts shall be made only by checks signed by such person or persons as are authorized by the Directors. All funds shall be maintained separately in the Association''s name.',
'Fiscal', 'bylaw', '11.5', '2002-11-11'),

-- ============================================================
-- SECTION 12: ROSTER OF UNIT OWNERS
-- ============================================================
(@aid, 'Roster of Unit Owners',
'Each Unit owner shall file with the Association a copy of the deed or other document showing his ownership. The Association shall maintain such information. The Association may rely upon the accuracy of such information for all purposes until notified in writing of changes therein as provided above. Only Unit owners of record, on the date notice of any meeting requiring their vote is given, shall be entitled to notice of and to vote at such meeting, unless prior to such meeting other owners shall produce adequate evidence of their interest and shall waive in writing notice of such meeting.',
'Members', 'bylaw', '12', '2002-11-11'),

-- ============================================================
-- SECTION 13: PARLIAMENTARY RULES
-- ============================================================
(@aid, 'Parliamentary Rules',
'To the extent desired by the Board of Directors, Roberts'' Rules of Order (official edition) shall govern the conduct of the Association meetings when not in conflict with the Condominium or Corporate Acts, case law, the Declaration, the Articles, these By-Laws, or rules and regulations adopted from time to time by the Board of Directors to regulate the participation of Unit owners at Board, membership and committee meetings, and to otherwise provide for orderly corporate operations.',
'Meetings & Voting', 'bylaw', '13', '2002-11-11'),

-- ============================================================
-- SECTION 14: AMENDMENTS
-- ============================================================
(@aid, 'Amendment — Notice',
'Notice of the subject matter of a proposed amendment shall be included in the notice of a meeting at which a proposed amendment is to be considered.',
'Amendments', 'bylaw', '14.1', '2002-11-11'),

(@aid, 'Amendment — Adoption',
'A resolution for the adoption of a proposed amendment may be proposed either by a majority of the Board of Directors or by not less than one-third (1/3rd) of the voting interests of the Association. After such proposal, membership approval of a proposed amendment must be by not less than a majority of the voting interests of the Association.',
'Amendments', 'bylaw', '14.2', '2002-11-11'),

(@aid, 'Amendment — Execution and Recording',
'A copy of each amendment shall be attached to a certificate certifying that the amendment was duly adopted as an amendment to the Declaration and By-Laws, which certificate shall be executed by the President or Vice President and attested by the Secretary or Assistant Secretary of the Association with the formalities of a deed. The amendment shall be effective when the certificate and a copy of the amendment is recorded in the Public Records of Volusia County.',
'Amendments', 'bylaw', '14.3', '2002-11-11'),

-- ============================================================
-- SECTION 15: RULES AND REGULATIONS
-- ============================================================
(@aid, 'Rules and Regulations — Board Authority to Adopt',
'The Board of Directors may, from time to time, adopt, amend or add to Rules and Regulations governing the use of Units, Common Elements, Limited Common Elements, Association property, and the operation of the Association. Copies of such adopted, amended or additional Rules and Regulations shall be furnished by the Board of Directors to each Unit owner not less than thirty (30) days prior to the effective date thereof, and shall be valid and enforceable notwithstanding whether recorded in the public records.',
'Amendments', 'bylaw', '15', '2002-11-11'),

-- ============================================================
-- SECTION 16: CONSTRUCTION
-- ============================================================
(@aid, 'Construction',
'Wherever the context so permits, the singular shall include the plural, the plural shall include the singular, and the use of any gender shall be deemed to include all genders.',
'Identity & Structure', 'bylaw', '16', '2002-11-11'),

-- ============================================================
-- SECTION 17: CAPTIONS
-- ============================================================
(@aid, 'Captions',
'The captions herein are inserted only as a matter of convenience and for reference, and in no way define or limit the scope of these By-Laws or the intent of any provision hereof.',
'Identity & Structure', 'bylaw', '17', '2002-11-11'),

-- ============================================================
-- SECTION 18: MANDATORY ARBITRATION OF DISPUTES
-- ============================================================
(@aid, 'Mandatory Arbitration of Disputes',
'Prior to commencing litigation, unresolved disputes between the Board and Unit owners as defined in Section 718.1255(1), Florida Statutes, must be arbitrated in mandatory non-binding arbitration proceedings as provided in the Condominium Act. This provision shall be in effect only so long as the Condominium Act mandates such arbitration.',
'Legal Compliance', 'bylaw', '18', '2002-11-11'),

-- ============================================================
-- SECTION 19: CONFLICT
-- ============================================================
(@aid, 'Conflict of Documents',
'If any irreconcilable conflict should exist, or hereafter arise, with respect to the interpretation of these By-Laws and the Declaration of Condominium or Articles of Incorporation, the provisions of the Declaration shall take precedence over the Articles of Incorporation which shall prevail over the provisions of these By-Laws.',
'Identity & Structure', 'bylaw', '19', '2002-11-11');
