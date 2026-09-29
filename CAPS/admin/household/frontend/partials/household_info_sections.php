<?php
/*
 * Household Head + Household Information sections (read-only).
 * Shared by view_household.php and edit_household.php so both pages show the
 * same details. Expects: $hh (head resident row + household_survey row), $name,
 * $headId, $age, $members, $status, $address, $income, $headIncome,
 * $memberCount, $classification, $ses, $withIncome, $hhLocation, hh_view_escape().
 * Optional: $isHeadless (household has no Head), $readOnlyNote (Edit page note).
 */
$isHeadless = $isHeadless ?? false;
$readOnlyNote = $readOnlyNote ?? '';
?>
                <!-- Household Head — the Head's own personal/basic information -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined">account_box</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800">Household Head</h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Personal information from the CAPS Resident profile.</p>
                            </div>
                        </div>
                        <?php if ($readOnlyNote !== ''): ?>
                            <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-500 text-[9px] font-black uppercase tracking-wider" title="<?= hh_view_escape($readOnlyNote); ?>">
                                <span class="material-symbols-outlined text-[12px] align-[-2px]">lock</span> Read-only · Resident Profiling
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isHeadless): ?>
                        <div class="p-6 md:p-8">
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 flex items-start gap-3">
                                <span class="material-symbols-outlined text-amber-600">person_off</span>
                                <div>
                                    <p class="text-sm font-black text-amber-800">This household has no Head.</p>
                                    <p class="text-xs font-semibold text-amber-700 mt-1">Assign a new Head through Edit Household → Change Household Head.</p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                    <?php
                    $headFields = [
                        ['Name', $name ?: '—', 'xl:col-span-2'],
                        ['Resident ID', !empty($hh['ResidentCode']) ? $hh['ResidentCode'] : ('#' . $headId), ''],
                        ['Age', $age === '—' ? '—' : $age . ' years old', ''],
                        ['Sex', $hh['Sex'] ?? '—', ''],
                        ['Civil Status', $hh['CivilStatus'] ?? '—', ''],
                        ['Date of Birth', !empty($hh['BirthDate']) ? date('F j, Y', strtotime((string) $hh['BirthDate'])) : '—', ''],
                        ['Place of Birth', $hh['BirthPlace'] ?? '—', ''],
                        ['Contact Number', $hh['ContactNumber'] ?? '—', ''],
                        ['Email', $hh['Email'] ?? '—', 'break-all'],
                        ['Religion', $hh['Religion'] ?? '—', ''],
                        ['Nationality', $hh['Nationality'] ?? '—', ''],
                        ['Educational Attainment', $hh['EducationLevel'] ?? '—', ''],
                        ['Employment Status', (($hh['EmploymentStatus'] ?? '') === 'Other' && !empty($hh['EmploymentStatusOther'])) ? $hh['EmploymentStatusOther'] : ($hh['EmploymentStatus'] ?? '—'), ''],
                        ['Occupation', $hh['Occupation'] ?? '—', ''],
                        ["Head's Own Monthly Income", '₱' . number_format($headIncome, 2), ''],
                    ];
                    $headTags = array_filter([
                        !empty($hh['IsSenior']) ? 'Senior Citizen' : '',
                        !empty($hh['IsPWD']) ? 'PWD' : '',
                        !empty($hh['IsSoloParent']) ? 'Solo Parent' : '',
                        !empty($hh['IsVoter']) ? 'Registered Voter' : '',
                    ]);
                    ?>
                    <div class="p-6 md:p-8">
                        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <?php foreach ($headFields as [$label, $value, $cls]): ?>
                                <div class="p-4 rounded-2xl bg-slate-50 <?= $cls; ?>">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?= hh_view_escape($label); ?></div>
                                    <div class="font-bold text-sm mt-1 <?= strpos($cls, 'break-all') !== false ? 'break-all' : ''; ?>">
                                        <?= hh_view_escape(trim((string) $value) !== '' ? $value : '—'); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($headTags): ?>
                            <div class="flex flex-wrap gap-2 mt-4">
                                <?php foreach ($headTags as $tag): ?>
                                    <span class="px-3 py-1 rounded-full bg-indigo-50 text-indigo-600 text-[10px] font-black uppercase tracking-wider"><?= hh_view_escape($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; /* headless */ ?>
                </section>

                <!-- Household Information — the household as a whole -->
                <section class="bg-white rounded-[28px] border border-slate-100 shadow-sm overflow-hidden">
                    <div class="px-6 md:px-8 py-5 border-b border-slate-50 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined">home</span>
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-800">Household Information</h2>
                                <p class="text-[10px] text-slate-400 font-semibold mt-0.5">Household-level details based on all household members.</p>
                            </div>
                        </div>
                        <span class="px-3 py-1 <?= $status === 'inactive' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'; ?> text-[9px] font-black uppercase tracking-wider rounded-full">
                            <?= hh_view_escape(strtoupper($status)); ?>
                        </span>
                    </div>

                    <div class="p-6 md:p-8">
                        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Household ID</div>
                                <div class="font-mono font-black text-sm mt-1"><?= hh_view_escape($hh['HouseholdID'] ?? '—'); ?></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Number of Household Members</div>
                                <div class="font-black text-sm mt-1"><?= (int) $memberCount; ?> <span class="text-[11px] font-semibold text-slate-400">(Head + <?= count($members); ?> member<?= count($members) === 1 ? '' : 's'; ?>)</span></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Registered</div>
                                <div class="font-bold text-sm mt-1"><?php $regDate = $hh['DateCreated'] ?? $hh['CreatedAt'] ?? null; ?><?= hh_view_escape($regDate ? date('F j, Y', strtotime((string) $regDate)) : '—'); ?></div>
                            </div>
                            <div class="p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Location</div>
                                <div class="font-mono text-[11px] font-bold mt-1"><?= $hhLocation ? hh_view_escape(number_format($hhLocation['lat'], 7) . ', ' . number_format($hhLocation['lng'], 7)) : 'Not pinned'; ?></div>
                            </div>
                            <div class="xl:col-span-4 p-4 rounded-2xl bg-slate-50">
                                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Complete Address</div>
                                <div class="font-semibold text-sm mt-1"><?= hh_view_escape($address ?: 'No address recorded'); ?></div>
                            </div>
                        </div>

                        <?php if ($status === 'inactive'): ?>
                            <div class="mt-4 rounded-2xl bg-rose-50 border border-rose-100 p-4">
                                <div class="text-[10px] font-black uppercase tracking-widest text-rose-400">Inactive Household</div>
                                <div class="text-sm font-bold text-rose-700 mt-1">
                                    Inactive since
                                    <?php $inactiveSince = $hh['inactive_since'] ?? $hh['removed_at'] ?? null; ?>
                                    <?= hh_view_escape($inactiveSince ? date('F j, Y', strtotime((string) $inactiveSince)) : '—'); ?>
                                    • By: <?= hh_view_escape($hh['inactive_by'] ?? 'System'); ?>
                                </div>
                                <div class="text-xs text-rose-500 mt-1">
                                    Reason:
                                    <?= hh_view_escape((($hh['removal_reason'] ?? '') === 'Other') ? ($hh['removal_reason_other'] ?? 'Other') : ($hh['removal_reason'] ?? '—')); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Household Socio-Economic Profile (combined income) -->
                        <div class="mt-6">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="material-symbols-outlined text-indigo-500">analytics</span>
                                <h3 class="text-sm font-black text-slate-800">Household Socio-Economic Profile</h3>
                            </div>
                            <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-4">
                                <div class="p-4 rounded-2xl bg-indigo-50/60">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400">Monthly Household Income</div>
                                    <div class="font-black text-lg mt-1 text-indigo-700">₱<?= number_format($income, 2); ?></div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1">Combined income of <?= (int) $withIncome; ?> of <?= (int) $memberCount; ?> member(s) with recorded income</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-indigo-50/60">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-indigo-400">Income Status</div>
                                    <div class="font-black text-sm mt-1 text-indigo-700"><?= hh_view_escape($classification); ?></div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1">Based on the combined monthly income</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-indigo-600 text-white">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-white/70">Socioeconomic Status</div>
                                    <div class="font-black text-lg mt-1"><?= hh_view_escape($ses['label'] ?? '—'); ?></div>
                                    <div class="text-[10px] font-semibold text-white/80 mt-1"><?= hh_view_escape($ses['range'] ?? ''); ?></div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Income per Member</div>
                                    <div class="font-black text-sm mt-1">₱<?= number_format((float) ($ses['per_capita'] ?? 0), 2); ?> / month</div>
                                    <div class="text-[10px] font-semibold text-slate-500 mt-1"><?= hh_view_escape(number_format((float) ($ses['ratio'] ?? 0), 2)); ?>× the poverty threshold</div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">House Type</div>
                                    <div class="font-black text-sm mt-1"><?= hh_view_escape($hh['house_type'] ?? 'Not recorded in survey'); ?></div>
                                </div>
                                <div class="p-4 rounded-2xl bg-slate-50">
                                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Tenure Status</div>
                                    <div class="font-black text-sm mt-1"><?= hh_view_escape($hh['tenure_status'] ?? $hh['housing_tenure'] ?? 'Not recorded in survey'); ?></div>
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-400 font-semibold mt-3 leading-relaxed">
                                Socioeconomic Status formula: (combined monthly income ₱<?= number_format($income, 2); ?> ÷ <?= (int) $memberCount; ?> member(s))
                                ÷ poverty threshold ₱<?= number_format((float) ($ses['poverty_line'] ?? 0), 2); ?> per person per month.
                                Below 1× Poor · 1–2× Low Income · 2–4× Lower Middle · 4–7× Middle · 7–12× Upper Middle · 12–20× Upper Income · 20× and above Rich (PIDS income classes).
                            </p>
                        </div>
                    </div>
                </section>

