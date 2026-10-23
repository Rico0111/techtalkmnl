/* Shared static-build/browser review projection. No network or platform API access. */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.TechTalkReviewModel = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const platforms = {
    carousell: { label: 'Carousell', hosts: ['www.carousell.ph', 'carousell.ph'] },
    google: { label: 'Google', hosts: ['www.google.com', 'google.com', 'maps.google.com'] },
    direct: { label: 'Customer feedback', hosts: [] }
  };
  const evidenceStates = ['SCREENSHOT_SUPPORTED', 'LIVE_MATCH', 'PARTIAL_MATCH', 'MISMATCH', 'UNVERIFIED', 'CUSTOMER_PROVIDED'];
  const linkStates = ['DIRECT_REVIEW_LINK', 'SOURCE_LISTING_LINK', 'SCREENSHOT_ONLY', 'PROFILE_ONLY', 'UNVERIFIED'];
  function fail(message) { throw new Error('Review data: ' + message); }
  function string(value, name, required = false) {
    if (typeof value !== 'string' || (required && !value.trim())) fail(name + ' must be ' + (required ? 'nonempty ' : '') + 'text');
    return value;
  }
  function date(value, name) {
    if (value != null && (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T/.test(value) || !Number.isFinite(Date.parse(value)))) fail(name + ' must be an ISO timestamp or null');
  }
  function url(value, platform, name) {
    if (value == null || value === '') return null;
    let parsed;
    try { parsed = new URL(value); } catch (_) { fail(name + ' is not a valid URL'); }
    if (parsed.protocol !== 'https:' || parsed.username || parsed.password || parsed.port || !platforms[platform].hosts.includes(parsed.hostname)) fail(name + ' is not an allowed HTTPS source URL');
    return parsed.href;
  }
  function project(data, now = Date.now()) {
    if (!data || data.schemaVersion !== 1 || !Array.isArray(data.reviews)) fail('schemaVersion 1 and reviews array required');
    const ids = new Set(), sourceIds = new Set(), warnings = [], reviews = [];
    for (const r of data.reviews) {
      if (!r || typeof r !== 'object') fail('record must be an object');
      string(r.id, 'id', true);
      if (!/^[a-zA-Z0-9_-]+$/.test(r.id) || ids.has(r.id)) fail('invalid or duplicate internal ID: ' + r.id);
      ids.add(r.id);
      if (!platforms[r.sourcePlatform]) fail('unsupported source platform: ' + r.sourcePlatform);
      const platform = r.sourcePlatform;
      if (r.sourceAccountId != null) string(r.sourceAccountId, 'sourceAccountId', true);
      if (r.platformReviewId != null) {
        string(r.platformReviewId, 'platformReviewId', true);
        string(r.sourceAccountId, 'sourceAccountId', true);
        const key = JSON.stringify([platform, r.sourceAccountId, r.platformReviewId]);
        if (sourceIds.has(key)) fail('duplicate platform review ID: ' + r.platformReviewId);
        sourceIds.add(key);
      }
      string(r.text, 'text');
      if (r.reviewer != null) string(r.reviewer, 'reviewer');
      if (r.rating != null && (!Number.isInteger(r.rating) || r.rating < 1 || r.rating > 5)) fail('rating must be an integer 1–5 or null');
      if (!r.text.trim() && r.rating == null) fail('text or rating required');
      if (r.rating != null) string(r.ratingBasis, 'ratingBasis', true);
      if (!evidenceStates.includes(r.verificationStatus)) fail('unknown evidence classification');
      if (!linkStates.includes(r.linkVerification)) fail('unknown link classification');
      for (const field of ['platformCreatedAt','platformUpdatedAt','lastSyncedAt']) date(r[field], field);
      if (r.date != null) string(r.date, 'date');
      const profile = url(r.sourceProfileUrl, platform, 'sourceProfileUrl');
      const direct = url(r.reviewUrl, platform, 'reviewUrl');
      const listing = url(r.sourceListingUrl, platform, 'sourceListingUrl');
      // Keep unsupported candidates private and report them; never promote by guessing.
      if (direct && r.linkVerification !== 'DIRECT_REVIEW_LINK') warnings.push(r.id + ': unverified review URL omitted');
      if (listing && r.linkVerification !== 'SOURCE_LISTING_LINK') warnings.push(r.id + ': unverified listing URL omitted');
      let sourceUrl = profile, sourceKind = profile ? 'PROFILE_ONLY' : 'UNVERIFIED';
      if (r.linkVerification === 'DIRECT_REVIEW_LINK') {
        if (!direct) fail(r.id + ': confirmed direct link missing URL');
        string(r.sourceLinkEvidence, 'sourceLinkEvidence', true);
        const parsed = new URL(direct);
        if (direct === profile || (platform === 'carousell' && /^\/(u|p)\//.test(parsed.pathname)) || /writereview|!12e1/i.test(direct)) fail(r.id + ': profile, listing, or write-review URL is not a direct review');
        sourceUrl = direct; sourceKind = 'DIRECT_REVIEW_LINK';
      } else if (r.linkVerification === 'SOURCE_LISTING_LINK') {
        if (!listing || platform !== 'carousell' || !/^\/p\/[^/]+\/?$/.test(new URL(listing).pathname)) fail(r.id + ': invalid confirmed listing');
        string(r.sourceLinkEvidence, 'sourceLinkEvidence', true);
        sourceUrl = listing; sourceKind = 'SOURCE_LISTING_LINK';
      }
      if (!sourceUrl) warnings.push(r.id + ': no verified public source URL; no link will be shown');
      const pub = r.publication;
      if (!pub || !['DRAFT','PUBLISHED','WITHDRAWN'].includes(pub.status)) fail('publication status required');
      if (pub.featured != null && typeof pub.featured !== 'boolean') fail('featured must be boolean');
      if (pub.status !== 'PUBLISHED' || pub.featured === false) continue;
      if (!['OWNER_AUTHORIZED_LEGACY','EXPLICIT_PERMISSION'].includes(pub.permission)) fail(r.id + ': publication permission required');
      string(pub.authorizationReference, 'publication authorization reference', true);
      if (['UNVERIFIED','PARTIAL_MATCH','MISMATCH'].includes(r.verificationStatus)) fail(r.id + ': evidence not eligible for publication');
      if (r.availability === 'DELETED' || r.availability === 'UNAVAILABLE') continue;
      if (platform === 'google') {
        if (r.googlePublicationPolicy !== 'CONFIRMED') fail(r.id + ': Google public-display permission not confirmed');
        string(r.policyApprovalReference, 'policyApprovalReference', true);
        date(r.expiresAt, 'expiresAt');
        if (!r.expiresAt) fail(r.id + ': Google expiration required');
        if (Date.parse(r.expiresAt) <= now) continue;
      }
      reviews.push({id:r.id, sourcePlatform:platform, reviewer:r.reviewer || null, text:r.text,
        rating:r.rating == null ? null : r.rating, ratingBasis:r.ratingBasis || null,
        date:r.date || null, dateType:r.dateType || null,
        platformCreatedAt:r.platformCreatedAt || null, platformUpdatedAt:r.platformUpdatedAt || null,
        lastSyncedAt:r.lastSyncedAt || null, verificationStatus:r.verificationStatus,
        linkVerification:r.linkVerification, sourceKind, sourceUrl,
        expiresAt:platform === 'google' ? r.expiresAt : null});
    }
    return {schemaVersion:1, reviews, warnings};
  }
  const escape = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function attribution(r) { return r.reviewer ? (r.sourcePlatform === 'carousell' ? '@' : '') + r.reviewer : 'Anonymous reviewer'; }
  function badge(n) { return n ? `${n} featured testimonial${n === 1 ? '' : 's'}` : 'Customer feedback'; }
  function sourceLabel(r) {
    if (r.sourceKind === 'DIRECT_REVIEW_LINK') return 'View Original Review';
    if (r.sourceKind === 'SOURCE_LISTING_LINK') return 'View Source Listing';
    return r.sourcePlatform === 'google' ? 'View on Google Maps' : 'View Reviews on Carousell';
  }
  function slides(reviews) {
    if (!reviews.length) return '<p class="reviews-empty">No featured testimonials are currently published. You can still visit our business profiles in Connections.</p>';
    return reviews.map((r,i) => {
      const who = attribution(r), label = platforms[r.sourcePlatform].label;
      const stars = r.rating == null ? '' : `<span class="review-stars" role="img" aria-label="${escape(r.rating + ' of 5 stars. ' + r.ratingBasis)}">${'★'.repeat(r.rating)}${'☆'.repeat(5-r.rating)}</span>`;
      const dateLabel = r.date ? `<span class="review-date"${r.dateType === 'relative-as-shown' ? ' title="Relative date preserved from the saved screenshot, not a live date"' : ''}>${escape(r.date)}</span>` : '';
      const link = r.sourceUrl ? `<a class="review-live-link" data-review-id="${escape(r.id)}" data-source-kind="${r.sourceKind}" href="${escape(r.sourceUrl)}" target="_blank" rel="noopener noreferrer" tabindex="${i ? -1 : 0}" aria-label="${escape(sourceLabel(r) + (r.sourceKind === 'PROFILE_ONLY' ? '; profile-level source' : '') + '; feedback by ' + who + '; opens in a new tab')}">${sourceLabel(r)} <span aria-hidden="true">↗</span></a>` : '<span class="review-source-unavailable">Original source link unavailable</span>';
      return `<div class="review-slide" data-review-id="${escape(r.id)}" id="review-slide-${escape(r.id)}" role="group" aria-roledescription="slide" aria-label="${escape('Review ' + (i+1) + ' of ' + reviews.length + ' from ' + who)}"><figure class="review-placeholder"><span class="review-quote" aria-hidden="true">“</span>${r.text ? `<blockquote><p class="review-text">${escape(r.text)}</p></blockquote>` : '<p class="review-text">Rating shared without a written comment.</p>'}<p class="review-meta">${stars}${dateLabel}<span class="review-source-tag">${escape(label)}</span></p><figcaption><strong>${escape(who)}</strong></figcaption>${link}</figure></div>`;
    }).join('\n');
  }
  function dots(reviews) { return reviews.map((r,i) => `<button class="review-dot${i ? '' : ' active'}" type="button" aria-label="Review slide ${i+1} of ${reviews.length}" aria-controls="review-slide-${escape(r.id)}" aria-current="${i ? 'false' : 'true'}" data-review-slide="${i}"></button>`).join('\n'); }
  // Accept only a sanitized public projection; ignore all private and unknown fields.
  function validatePublic(data) {
    if (!data || data.schemaVersion !== 1 || !Array.isArray(data.reviews)) fail('invalid public dataset');
    return project({schemaVersion:1, reviews:data.reviews.map(r => ({...r,
      sourceAccountId:'public-projection', platformReviewId:null,
      sourceProfileUrl:r.sourceKind === 'PROFILE_ONLY' ? r.sourceUrl : null,
      reviewUrl:r.sourceKind === 'DIRECT_REVIEW_LINK' ? r.sourceUrl : null,
      sourceListingUrl:r.sourceKind === 'SOURCE_LISTING_LINK' ? r.sourceUrl : null,
      linkVerification:r.sourceKind || 'UNVERIFIED',sourceLinkEvidence:'validated build projection',
      publication:{status:'PUBLISHED',permission:'EXPLICIT_PERMISSION',authorizationReference:'validated build projection'},
      googlePublicationPolicy:r.sourcePlatform === 'google' ? 'CONFIRMED' : null, policyApprovalReference:'validated build projection'
    }))}).reviews;
  }
  return {project, validatePublic, slides, dots, badge, attribution, escape};
});
