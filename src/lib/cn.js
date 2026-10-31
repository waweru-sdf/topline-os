import clsx from 'clsx'

/** Join class names conditionally: cn('a', cond && 'b', { c: flag }) */
export const cn = (...args) => clsx(...args)
