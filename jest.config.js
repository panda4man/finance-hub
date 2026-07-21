module.exports = {
  rootDir: 'src',
  testRegex: '.*\\.spec\\.ts$',
  // Integration specs share one real Postgres and each run migrate() in
  // beforeAll; concurrent workers race to create the same tables/types.
  maxWorkers: 1,
  transform: {
    '^.+\\.(t|j)s$': 'ts-jest',
  },
  collectCoverageFrom: ['**/*.(t|j)s'],
  coverageDirectory: '../coverage',
  testEnvironment: 'node',
};
