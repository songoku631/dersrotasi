import { yksCoefficients } from './yksCoefficients.js'

// Rank datasets do not enable score calculation: coefficients must exist separately.
// Dataset years refer to backend/config/yks/official_rank_distributions.php.
export const yksYears = [2026, 2025, 2024, 2023].map(year => ({
  year,
  score: {
    enabled: Boolean(yksCoefficients[year]?.calibration),
    coefficientYear: yksCoefficients[year]?.calibration ? year : null,
    status: yksCoefficients[year]?.status ?? 'unavailable',
  },
  rank: { datasetYear: year, scoreTypes: ['SAY', 'EA', 'SÖZ', 'DİL'] },
}))

export const defaultScoreYear = yksYears.find(item => item.score.enabled)?.year
